<?php

namespace Troliveira\apibbphp;

use Troliveira\ApiBbPhp\Exceptions\InternalServerErrorException;
use Troliveira\ApiBbPhp\Exceptions\InvalidRequestException;
use Troliveira\ApiBbPhp\Exceptions\ServiceUnavailableException;
use Troliveira\ApiBbPhp\Exceptions\UnauthorizedException;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Message;
use JetBrains\PhpStorm\NoReturn;

class BankingBB
{
    protected $urlToken;
    protected $header;
    protected $token;
    protected $config;
    protected $urls;
    protected $uriToken;
    protected $uriCobranca;
    protected $clientToken;
    protected $clientCobranca;
    protected $fields;
    protected $headers;
    // protected $optionsRequest = [];

    private $client;
    // function __construct(array $config)
    function __construct($config)
    {
        $this->config = $config;
        if ($config['endPoints'] == 1) {
            $this->urls = 'https://api.bb.com.br/cobrancas/v2';
            $this->urlToken = 'https://oauth.bb.com.br/oauth/token';
            //GuzzleHttp
            $this->uriToken = 'https://oauth.bb.com.br/oauth/token';
            $this->uriCobranca = 'https://api.bb.com.br';
        } else {
            $this->urls = 'https://api.sandbox.bb.com.br/cobrancas/v2';
            $this->urlToken = 'https://oauth.sandbox.bb.com.br/oauth/token';
            //GuzzleHttp
            $this->uriToken = 'https://oauth.sandbox.bb.com.br';
            $this->uriCobranca = 'https://api.sandbox.bb.com.br';
        }
        $this->clientToken = new Client([
            'base_uri' => $this->uriToken,
        ]);
        $this->clientCobranca = new Client([
            'base_uri' => $this->uriCobranca,
        ]);

        //startar o token
        if (isset($this->config['token'])) {
            if ($this->config['token'] != '') {
                $this->setToken($this->config['token']);
            } else {
                $this->gerarToken();
            }
        }
    }

    ######################################################
    ############## TOKEN #################################
    ######################################################

    public function gerarToken()
    {
        try {
            $response = $this->clientToken->request(
                'POST',
                '/oauth/token',
                [
                    'headers' => [
                        'Accept' => '*/*',
                        'Content-Type' => 'application/x-www-form-urlencoded',
                        'Authorization' => 'Basic ' . base64_encode($this->config['client_id'] . ':' . $this->config['client_secret']) . ''
                    ],
                    'verify' => false,
                    'form_params' => [
                        'grant_type' => 'client_credentials',
                        'scope' => 'cobrancas.boletos-info cobrancas.boletos-requisicao'
                    ]
                ]
            );
            $retorno = json_decode($response->getBody()->getContents());
            if (isset($retorno->access_token)) {
                $this->token = $retorno->access_token;
            }
            return $this->token;
        } catch (\Exception $e) {
            return new Exception("Falha ao gerar Token: {$e->getMessage()}");
        }
    }

    public function setToken(string $token)
    {
        $this->token = $token;
    }

    public function getToken()
    {
        return $this->token;
    }

    protected function fields(array $fields, string $format = "json"): void
    {
        if ($format == "json") {
            $this->fields = (!empty($fields) ? json_encode($fields) : null);
        }
        if ($format == "query") {
            $this->fields = (!empty($fields) ? http_build_query($fields) : null);
        }
    }

    protected function headers(array $headers): void
    {
        if (!$headers) {
            return;
        }
        foreach ($headers as $k => $v) {
            $this->header($k, $v);
        }
    }

    protected function header(string $key, string $value): void
    {
        if (!$key || is_int($key)) {
            return;
        }
        $keys = filter_var($key, FILTER_SANITIZE_STRIPPED);
        $values = filter_var($value, FILTER_SANITIZE_STRIPPED);
        $this->headers[] = "{$keys}: {$values}";
    }
    ######################################################
    ############## FIM - TOKEN ###########################
    ######################################################

    ######################################################
    ############## COBRANÇAS #############################
    ######################################################
    public function registrarBoleto(array $fields)
    {
        $filters = [];
        try {
            $response = $this->clientCobranca->request(
                'POST',
                '/cobrancas/v2/boletos',
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'X-Developer-Application-Key' => $this->config['application_key'],
                        'Authorization' => 'Bearer ' . $this->token . ''
                    ],
                    'verify' => false,
                    'query' => [
                        'gw-dev-app-key' => $this->config['application_key'],
                    ],
                    'body' => json_encode($fields),
                ]
            );
            $statusCode = $response->getStatusCode();
            $result = json_decode($response->getBody()->getContents());
            return array('status' => $statusCode, 'response' => $result);
        } catch (ClientException $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            $requestParameters = $e->getRequest();
            $bodyContent = json_decode($e->getResponse()->getBody()->getContents());

            switch ($statusCode) {
                case InvalidRequestException::HTTP_STATUS_CODE:
                    $exception = new InvalidRequestException($bodyContent->erros[0]->mensagem);
                    $exception->setRequestParameters($requestParameters);
                    $exception->setBodyContent($bodyContent);
                    throw $exception;
                case UnauthorizedException::HTTP_STATUS_CODE:
                    $exception = new UnauthorizedException($bodyContent->message);
                    $exception->setRequestParameters($requestParameters);
                    $exception->setBodyContent($bodyContent);
                    throw $exception;
                case InternalServerErrorException::HTTP_STATUS_CODE:
                    $exception = new InternalServerErrorException($bodyContent->erros[0]->mensagem);
                    $exception->setRequestParameters($requestParameters);
                    $exception->setBodyContent($bodyContent);
                    throw $exception;
                case ServiceUnavailableException::HTTP_STATUS_CODE:
                    $exception = new ServiceUnavailableException("SERVIÇO INDISPONÍVEL");
                    $exception->setRequestParameters($requestParameters);
                    throw $exception;
                default:
                    throw $e;
            }
        } catch (\Exception $e) {
            $response = $e->getMessage();
            return ['error' => "Falha ao incluir Boleto Cobranca: {$response}"];
        }
    }

    public function alterarBoleto(string $id, array $fields)
    {
        try {
            $response = $this->clientCobranca->request(
                'PATCH',
                "/cobrancas/v2/boletos/{$id}",
                [
                    'headers' => [
                        'accept' => 'application/json',
                        'Content-Type' => 'application/json',
                        'X-Developer-Application-Key' => $this->config['application_key'],
                        'Authorization' => 'Bearer ' . $this->token . ''
                    ],
                    'verify' => false,
                    'query' => [
                        'gw-dev-app-key' => $this->config['application_key'],
                    ],
                    'body' => json_encode($fields),
                ]
            );
            $statusCode = $response->getStatusCode();
            $result = json_decode($response->getBody()->getContents());
            return array('status' => $statusCode, 'response' => $result);
        } catch (ClientException $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            $requestParameters = $e->getRequest();
            $bodyContent = json_decode($e->getResponse()->getBody()->getContents());

            switch ($statusCode) {
                case InvalidRequestException::HTTP_STATUS_CODE:
                    $exception = new InvalidRequestException($bodyContent->erros[0]->mensagem);
                    $exception->setRequestParameters($requestParameters);
                    $exception->setBodyContent($bodyContent);
                    throw $exception;
                case UnauthorizedException::HTTP_STATUS_CODE:
                    $exception = new UnauthorizedException($bodyContent->message);
                    $exception->setRequestParameters($requestParameters);
                    $exception->setBodyContent($bodyContent);
                    throw $exception;
                case InternalServerErrorException::HTTP_STATUS_CODE:
                    $exception = new InternalServerErrorException($bodyContent->erros[0]->mensagem);
                    $exception->setRequestParameters($requestParameters);
                    $exception->setBodyContent($bodyContent);
                    throw $exception;
                case ServiceUnavailableException::HTTP_STATUS_CODE:
                    $exception = new ServiceUnavailableException("SERVIÇO INDISPONÍVEL");
                    $exception->setRequestParameters($requestParameters);
                    throw $exception;
                default:
                    throw $e;
            }
        } catch (\Exception $e) {
            $response = $e->getMessage();
            return ['error' => "Falha ao alterar Boleto Cobranca: {$response}"];
        }
    }

    public function detalheDoBoleto(string $id)
    {
        try {
            $response = $this->clientCobranca->request(
                'GET',
                "/cobrancas/v2/boletos/{$id}",
                [
                    'headers' => [
                        'X-Developer-Application-Key' => $this->config['application_key'],
                        'Authorization' => 'Bearer ' . $this->token . ''
                    ],
                    'verify' => false,
                    'query' => [
                        'numeroConvenio' => $this->config['numeroConvenio'],
                    ],
                ]
            );
            $statusCode = $response->getStatusCode();
            $result = json_decode($response->getBody()->getContents());
            return array('status' => $statusCode, 'response' => $result);
        } catch (ClientException $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            $requestParameters = $e->getRequest();
            $bodyContent = json_decode($e->getResponse()->getBody()->getContents());

            switch ($statusCode) {
                case InvalidRequestException::HTTP_STATUS_CODE:
                    $exception = new InvalidRequestException($bodyContent->erros[0]->mensagem);
                    $exception->setRequestParameters($requestParameters);
                    $exception->setBodyContent($bodyContent);
                    throw $exception;
                case UnauthorizedException::HTTP_STATUS_CODE:
                    $exception = new UnauthorizedException($bodyContent->message);
                    $exception->setRequestParameters($requestParameters);
                    $exception->setBodyContent($bodyContent);
                    throw $exception;
                case InternalServerErrorException::HTTP_STATUS_CODE:
                    $exception = new InternalServerErrorException($bodyContent->erros[0]->mensagem);
                    $exception->setRequestParameters($requestParameters);
                    $exception->setBodyContent($bodyContent);
                    throw $exception;
                case ServiceUnavailableException::HTTP_STATUS_CODE:
                    $exception = new ServiceUnavailableException("SERVIÇO INDISPONÍVEL");
                    $exception->setRequestParameters($requestParameters);
                    throw $exception;
                default:
                    throw $e;
            }
        } catch (\Exception $e) {
            $response = $e->getMessage();
            return ['error' => "Falha ao detalhar Boleto Cobranca: {$response}"];
        }
    }

    public function listarBoletos($filters)
    {
        try {
            $response = $this->clientCobranca->request(
                'GET',
                "/cobrancas/v2/boletos",
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Authorization' => 'Bearer ' . $this->token . ''
                    ],
                    'verify' => false,
                    'query' => [
                        'gw-dev-app-key' => $this->config['application_key'],
                        'indicadorSituacao' => $filters['indicadorSituacao'],
                        'agenciaBeneficiario' => $filters["agenciaBeneficiario"],
                        'contaBeneficiario' => $filters["contaBeneficiario"],
                        'cnpjPagador' => '',
                        'digitoCNPJPagador' => '',
                        'digitoCNPJPagador' => '',
                        'codigoEstadoTituloCobranca' => $filters['codigoEstadoTituloCobranca'],
                        'boletoVencido' => $filters['boletoVencido'],
                    ],
                ]
            );
            $statusCode = $response->getStatusCode();
            $result = json_decode($response->getBody()->getContents());
            return array('status' => $statusCode, 'response' => $result);
        } catch (ClientException $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            $requestParameters = $e->getRequest();
            $bodyContent = json_decode($e->getResponse()->getBody()->getContents());

            switch ($statusCode) {
                case InvalidRequestException::HTTP_STATUS_CODE:
                    $exception = new InvalidRequestException($bodyContent->erros[0]->mensagem);
                    $exception->setRequestParameters($requestParameters);
                    $exception->setBodyContent($bodyContent);
                    throw $exception;
                case UnauthorizedException::HTTP_STATUS_CODE:
                    $exception = new UnauthorizedException($bodyContent->message);
                    $exception->setRequestParameters($requestParameters);
                    $exception->setBodyContent($bodyContent);
                    throw $exception;
                case InternalServerErrorException::HTTP_STATUS_CODE:
                    $exception = new InternalServerErrorException($bodyContent->erros[0]->mensagem);
                    $exception->setRequestParameters($requestParameters);
                    $exception->setBodyContent($bodyContent);
                    throw $exception;
                case ServiceUnavailableException::HTTP_STATUS_CODE:
                    $exception = new ServiceUnavailableException("SERVIÇO INDISPONÍVEL");
                    $exception->setRequestParameters($requestParameters);
                    throw $exception;
                default:
                    throw $e;
            }
        } catch (\Exception $e) {
            $response = $e->getMessage();
            return ['error' => "Falha ao baixar Boleto Cobranca: {$response}"];
        }
    }

    public function baixarBoleto(string $id)
    {
        $fields['numeroConvenio'] = $this->config['numeroConvenio'];
        try {
            $response = $this->clientCobranca->request(
                'POST',
                "/cobrancas/v2/boletos/{$id}/baixar",
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'X-Developer-Application-Key' => $this->config['application_key'],
                        'Authorization' => 'Bearer ' . $this->token . ''
                    ],
                    'verify' => false,
                    'body' => json_encode($fields),
                ]
            );
            $statusCode = $response->getStatusCode();
            $result = json_decode($response->getBody()->getContents());
            return array('status' => $statusCode, 'response' => $result);
        } catch (ClientException $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            $requestParameters = $e->getRequest();
            $bodyContent = json_decode($e->getResponse()->getBody()->getContents());

            switch ($statusCode) {
                case InvalidRequestException::HTTP_STATUS_CODE:
                    $exception = new InvalidRequestException($bodyContent->erros[0]->mensagem);
                    $exception->setRequestParameters($requestParameters);
                    $exception->setBodyContent($bodyContent);
                    throw $exception;
                case UnauthorizedException::HTTP_STATUS_CODE:
                    $exception = new UnauthorizedException($bodyContent->message);
                    $exception->setRequestParameters($requestParameters);
                    $exception->setBodyContent($bodyContent);
                    throw $exception;
                case InternalServerErrorException::HTTP_STATUS_CODE:
                    $exception = new InternalServerErrorException($bodyContent->erros[0]->mensagem);
                    $exception->setRequestParameters($requestParameters);
                    $exception->setBodyContent($bodyContent);
                    throw $exception;
                case ServiceUnavailableException::HTTP_STATUS_CODE:
                    $exception = new ServiceUnavailableException("SERVIÇO INDISPONÍVEL");
                    $exception->setRequestParameters($requestParameters);
                    throw $exception;
                default:
                    throw $e;
            }
        } catch (\Exception $e) {
            $response = $e->getMessage();
            return ['error' => "Falha ao baixar Boleto Cobranca: {$response}"];
        }
    }

    public function consultaPixBoleto(string $id)
    {
        try {
            $response = $this->clientCobranca->request(
                'GET',
                "/cobrancas/v2/boletos/{$id}/pix",
                [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Authorization' => 'Bearer ' . $this->token . ''
                    ],
                    'verify' => false,
                    'query' => [
                        'gw-dev-app-key' => $this->config['application_key'],
                        'numeroConvenio' => $this->config['numeroConvenio'],
                    ],
                ]
            );
            $statusCode = $response->getStatusCode();
            $result = json_decode($response->getBody()->getContents());
            return array('status' => $statusCode, 'response' => $result);
        } catch (ClientException $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            $requestParameters = $e->getRequest();
            $bodyContent = json_decode($e->getResponse()->getBody()->getContents());

            switch ($statusCode) {
                case InvalidRequestException::HTTP_STATUS_CODE:
                    $exception = new InvalidRequestException($bodyContent->erros[0]->mensagem);
                    $exception->setRequestParameters($requestParameters);
                    $exception->setBodyContent($bodyContent);
                    throw $exception;
                case UnauthorizedException::HTTP_STATUS_CODE:
                    $exception = new UnauthorizedException($bodyContent->message);
                    $exception->setRequestParameters($requestParameters);
                    $exception->setBodyContent($bodyContent);
                    throw $exception;
                case InternalServerErrorException::HTTP_STATUS_CODE:
                    $exception = new InternalServerErrorException($bodyContent->erros[0]->mensagem);
                    $exception->setRequestParameters($requestParameters);
                    $exception->setBodyContent($bodyContent);
                    throw $exception;
                case ServiceUnavailableException::HTTP_STATUS_CODE:
                    $exception = new ServiceUnavailableException("SERVIÇO INDISPONÍVEL");
                    $exception->setRequestParameters($requestParameters);
                    throw $exception;
                default:
                    throw $e;
            }
        } catch (\Exception $e) {
            $response = $e->getMessage();
            return ['error' => "Falha ao baixar Boleto Cobranca: {$response}"];
        }
    }

    public function cancelarPixBoleto(string $id)
    {
        $fields['numeroConvenio'] = $this->config['numeroConvenio'];
        try {
            $response = $this->clientCobranca->request(
                'POST',
                "/cobrancas/v2/boletos/{$id}/cancelar-pix",
                [
                    'headers' => [
                        'accept' => 'application/json',
                        'Content-Type' => 'application/json',
                        'Authorization' => 'Bearer ' . $this->token . ''
                    ],
                    'verify' => false,
                    'query' => [
                        'gw-dev-app-key' => $this->config['application_key'],
                    ],
                    'body' => json_encode($fields),
                ]
            );
            $statusCode = $response->getStatusCode();
            $result = json_decode($response->getBody()->getContents());
            return array('status' => $statusCode, 'response' => $result);
        } catch (ClientException $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            $requestParameters = $e->getRequest();
            $bodyContent = json_decode($e->getResponse()->getBody()->getContents());

            switch ($statusCode) {
                case InvalidRequestException::HTTP_STATUS_CODE:
                    $exception = new InvalidRequestException($bodyContent->erros[0]->mensagem);
                    $exception->setRequestParameters($requestParameters);
                    $exception->setBodyContent($bodyContent);
                    throw $exception;
                case UnauthorizedException::HTTP_STATUS_CODE:
                    $exception = new UnauthorizedException($bodyContent->message);
                    $exception->setRequestParameters($requestParameters);
                    $exception->setBodyContent($bodyContent);
                    throw $exception;
                case InternalServerErrorException::HTTP_STATUS_CODE:
                    $exception = new InternalServerErrorException($bodyContent->erros[0]->mensagem);
                    $exception->setRequestParameters($requestParameters);
                    $exception->setBodyContent($bodyContent);
                    throw $exception;
                case ServiceUnavailableException::HTTP_STATUS_CODE:
                    $exception = new ServiceUnavailableException("SERVIÇO INDISPONÍVEL");
                    $exception->setRequestParameters($requestParameters);
                    throw $exception;
                default:
                    throw $e;
            }
        } catch (\Exception $e) {
            $response = $e->getMessage();
            return ['error' => "Falha ao baixar Boleto Cobranca: {$response}"];
        }
    }

    public function gerarPixBoleto(string $id)
    {
        $fields['numeroConvenio'] = $this->config['numeroConvenio'];
        try {
            $response = $this->clientCobranca->request(
                'POST',
                "/cobrancas/v2/boletos/{$id}/gerar-pix",
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->token . '',
                        'accept' => 'application/json',
                        'Content-Type' => 'application/json',
                        // 'X-Developer-Application-Key' => $this->config['application_key'],
                    ],
                    'verify' => false,
                    'query' => [
                        'gw-dev-app-key' => $this->config['application_key'],
                    ],
                    'body' => '{"numeroConvenio": 3128557}',
                ]
            );
            $statusCode = $response->getStatusCode();
            $result = json_decode($response->getBody()->getContents());
            return array('status' => $statusCode, 'response' => $result);
        } catch (ClientException $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            $requestParameters = $e->getRequest();
            $bodyContent = json_decode($e->getResponse()->getBody()->getContents());

            switch ($statusCode) {
                case InvalidRequestException::HTTP_STATUS_CODE:
                    $exception = new InvalidRequestException($bodyContent->erros[0]->mensagem);
                    $exception->setRequestParameters($requestParameters);
                    $exception->setBodyContent($bodyContent);
                    throw $exception;
                case UnauthorizedException::HTTP_STATUS_CODE:
                    $exception = new UnauthorizedException($bodyContent->message);
                    $exception->setRequestParameters($requestParameters);
                    $exception->setBodyContent($bodyContent);
                    throw $exception;
                case InternalServerErrorException::HTTP_STATUS_CODE:
                    $exception = new InternalServerErrorException($bodyContent->erros[0]->mensagem);
                    $exception->setRequestParameters($requestParameters);
                    $exception->setBodyContent($bodyContent);
                    throw $exception;
                case ServiceUnavailableException::HTTP_STATUS_CODE:
                    $exception = new ServiceUnavailableException("SERVIÇO INDISPONÍVEL");
                    $exception->setRequestParameters($requestParameters);
                    throw $exception;
                default:
                    throw $e;
            }
        } catch (\Exception $e) {
            $response = $e->getMessage();
            return ['error' => "Falha ao baixar Boleto Cobranca: {$response}"];
        }
    }

    public function gerarPixBoleto2(string $id, array $fields)
    {
        $this->headers([
            "Authorization"     => "Bearer " . $this->token,
            "accept"      => "application/json",
            "Content-Type"      => "application/json",
            // "X-Developer-Application-Key" => $this->config['application_key']
        ]);
        $this->fields($fields, 'json');

        $curl = curl_init("https://api.sandbox.bb.com.br/cobrancas/v2/boletos/00031285570000150024/gerar-pix?gw-dev-app-key=d27be77909ffab001369e17d80050056b9b1a5b0");
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => '{
                "numeroConvenio": 3128557
              }',
            CURLOPT_HTTPHEADER => ($this->headers),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLINFO_HEADER_OUT => true
        ]);

        $gerarPixBoleto = json_decode(curl_exec($curl));
        return $gerarPixBoleto;
    }

    ######################################################
    ############## FIM - COBRANÇAS #######################
    ######################################################





    //NADA FEITO DAQUI PARA BAIXO



    ######################################################
    ############## PAGAMENTOS ############################
    ######################################################
    public function pagarBoletoLinha(string $linhaDigitavel)
    {
        // $this->headers([
        //     "accept"            => "application/json",
        //     // "Content-Type"      => "application/json",
        //     // "Authorization"     => "Bearer " . $this->getToken()->access_token,
        //     // "X-Developer-Application-Key" => $this->config['application_key']
        // ]);
        // //falta colocar produção
        // $curl = curl_init("https://api.hm.bb.com.br/testes-portal-desenvolvedor/v1/boletos-cobranca/{$linhaDigitavel}/pagar?gw-app-key={$this->config['application_key']}");
        // curl_setopt_array($curl,[
        //     CURLOPT_RETURNTRANSFER => true,
        //     CURLOPT_MAXREDIRS => 10,
        //     CURLOPT_TIMEOUT => 30,
        //     CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        //     CURLOPT_CUSTOMREQUEST => "POST",
        //     CURLOPT_POSTFIELDS => '',
        //     CURLOPT_HTTPHEADER => ($this->headers),
        //     CURLOPT_SSL_VERIFYPEER => false,
        //     CURLINFO_HEADER_OUT => true
        // ]);

        // $pagarBoletoLinha = json_decode(curl_exec($curl));
        // return $pagarBoletoLinha;
    }

    public function pagarBoletoPix(string $pix)
    {
        // $this->headers([
        //     "Content-Type"      => "application/json",
        //     "accept"            => "application/json",
        //     // "Authorization"     => "Bearer " . $this->getToken()->access_token,
        //     // "X-Developer-Application-Key" => $this->config['application_key']
        // ]);
        // //falta colocar produção
        // $curl = curl_init("https://api.hm.bb.com.br/testes-portal-desenvolvedor/v1/boletos-pix/pagar?gw-app-key={$this->config['application_key']}");
        // curl_setopt_array($curl,[
        //     CURLOPT_RETURNTRANSFER => true,
        //     CURLOPT_MAXREDIRS => 10,
        //     CURLOPT_TIMEOUT => 30,
        //     CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        //     CURLOPT_CUSTOMREQUEST => "POST",
        //     CURLOPT_POSTFIELDS => '{
        //         "pix": "00020101021226870014br.gov.bcb.pix2565qrcodepix-h.bb.com.br/pix/v2/c8ecd24d-f648-44ff-b568-6806fbd3d01a5204000053039865802BR5920ALAN GUIACHERO BUENO6008BRASILIA62070503***6304072C"
        //       ',
        //     CURLOPT_HTTPHEADER => ($this->headers),
        //     CURLOPT_SSL_VERIFYPEER => false,
        //     CURLINFO_HEADER_OUT => true
        // ]);

        // $pagarBoletoPix = json_decode(curl_exec($curl));
        // return $pagarBoletoPix;
    }
    ######################################################
    ############## FIM - PAGAMENTOS ######################
    ######################################################


    ######################################################
    ############## QRCODES ###############################
    ######################################################
    public function gerarQRCode(string $pix)
    {
        // $this->headers([
        //     "Content-Type"      => "application/json",
        //     "accept"            => "application/json",
        //     "Authorization"     => "Bearer " . $this->getToken()->access_token,
        //     "X-Developer-Application-Key" => $this->config['application_key']
        // ]);
        // //falta colocar produção
        // $curl = curl_init("https://api.sandbox.bb.com.br/pix-bb/v1/arrecadacao-qrcodes?gw-dev-app-key={$this->config['application_key']}");
        // curl_setopt_array($curl,[
        //     CURLOPT_RETURNTRANSFER => true,
        //     CURLOPT_MAXREDIRS => 10,
        //     CURLOPT_TIMEOUT => 30,
        //     CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        //     CURLOPT_CUSTOMREQUEST => "POST",
        //     CURLOPT_POSTFIELDS => '{
        //         "numeroConvenio": 62191,
        //         "indicadorCodigoBarras": "S",
        //         "codigoGuiaRecebimento": "83660000000199800053846101173758000000000000",
        //         "emailDevedor": "contribuinte.silva@provedor.com.br",
        //         "codigoPaisTelefoneDevedor": 55,
        //         "dddTelefoneDevedor": 61,
        //         "numeroTelefoneDevedor": "999731240",
        //         "codigoSolicitacaoBancoCentralBrasil": "88a33759-78b0-43b7-8c60-e5e3e7cb55fe",
        //         "descricaoSolicitacaoPagamento": "Arrecadação Pix",
        //         "valorOriginalSolicitacao": 19.98,
        //         "cpfDevedor": "19917885250",
        //         "nomeDevedor": "Contribuinte da Silva",
        //         "quantidadeSegundoExpiracao": 3600,
        //         "listaInformacaoAdicional": [
        //           {
        //             "codigoInformacaoAdicional": "IPTU",
        //             "textoInformacaoAdicional": "COTA ÚNICA 2021"
        //           }
        //         ]
        //       }',
        //     CURLOPT_HTTPHEADER => ($this->headers),
        //     CURLOPT_SSL_VERIFYPEER => false,
        //     CURLINFO_HEADER_OUT => true
        // ]);

        // $gerarQRCode = json_decode(curl_exec($curl));
        // return $gerarQRCode;
    }
    ######################################################
    ############## FIM - QRCODES #########################
    ######################################################
}
