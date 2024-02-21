<?php

namespace crawler;

use DOMDocument;
use DomXPath;

class WebCrawler
{
    private $urlSintegra = 'http://www.sintegra.fazenda.pr.gov.br/sintegra/';
    private $urlCaptcha = 'http://www.sintegra.fazenda.pr.gov.br/sintegra/captcha';
    private $urlSintegraNextInput = 'http://www.sintegra.fazenda.pr.gov.br/sintegra/sintegra1/consultar';
    private $captchaImgPath = 'crawler/img/captcha.jpeg';

    public function formatCnpj($cnpj)
    {
        // Early return caso CNPJ seja inválido
        if (strlen($cnpj) !== 14)
        {
            echo "ERRO! Confira o número informado" . PHP_EOL;
            return;
        }

        // Formatação CNPJ
        $cnpj = preg_replace("/\D/", '', $cnpj);
        $cnpjFormatted = preg_replace("/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/", "\$1.\$2.\$3/\$4-\$5", $cnpj);

        return $cnpjFormatted;
    }

    public function searchCnpj($cnpj)
    {
        $cookie = $this->requestCookie();

        $this->requestCaptcha($cookie);

        echo "A imagem do captcha foi baixada. Abra a pasta crawler/img e informe o código manualmente. " . PHP_EOL . "Informe o captcha: ";
        $captcha = trim(fgets(STDIN));

        unlink("crawler/img/captcha.jpeg");

        $response = $this->searchCompany($cnpj, $captcha, $cookie);

        return $response;
    }

    private function requestCookie()
    {
        $curl = curl_init();

        $curlOptions = [
            CURLOPT_URL => $this->urlSintegra,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_HEADER => 1,
            CURLOPT_RETURNTRANSFER => 1
        ];

        curl_setopt_array($curl, $curlOptions);

        $response = curl_exec($curl);

        if ($response == false)
        {
            echo "ERRO! requisição cookie" . PHP_EOL . curl_error($curl);
            exit();
        }

        preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $response,  $match);

        parse_str($match[1][0], $cookie);

        return $cookie;
    }

    private function requestCaptcha($cookie)
    {
        $curl = curl_init();

        $file = fopen($this->captchaImgPath, "wb");

        $curlOptions = [
            CURLOPT_FILE => $file,
            CURLOPT_HEADER => 0,
            CURLOPT_FOLLOWLOCATION => 1,
            CURLOPT_URL => $this->urlCaptcha,
            CURLOPT_HTTPHEADER => [
                "Host: www.sintegra.fazenda.pr.gov.br",
                "Cookie: CAKEPHP={$cookie['CAKEPHP']}; path=/sintegra",
                "Connection: keep-alive",
                "Upgrade-Insecure-Requests: 1",
                "Content-Type: image/jpeg",
                "Accept: */*"
            ],
            CURLOPT_TIMEOUT => 60
        ];

        curl_setopt_array($curl, $curlOptions);

        $response = curl_exec($curl);

        if ($response == false)
        {
            echo "ERRO! requisição captcha" . PHP_EOL . curl_error($curl);
            exit();
        }

        curl_close($curl);

        fclose($file);

        return $this->captchaImgPath;
    }

    private function searchCompany($cnpj, $captcha, $cookie)
    {
        $curl = curl_init();

        $data = [
            "_method" => "POST",
            "data[Sintegra1][CodImage]" => $captcha,
            "data[Sintegra1][Cnpj]" => $cnpj,
            "empresa" => "Consultar Empresa",
            "data[Sintegra1][Cadicms]" => "",
            "data[Sintegra1][CadicmsProdutor]" => "",
            "data[Sintegra1][CnpjCpfProdutor]" => "",
        ];

        $curlOptions = [
            CURLOPT_URL => $this->urlSintegra,
            CURLOPT_POST => 1,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_HEADER => 1,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/x-www-form-urlencoded",
                "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
                "Accept-Language: pt-BR,pt;q=0.9",
                "Host: www.sintegra.fazenda.pr.gov.br",
                "Origin: http://www.sintegra.fazenda.pr.gov.br",
                "Connection: keep-alive",
                "Upgrade-Insecure-Requests: 1",
                "Referer: http://www.sintegra.fazenda.pr.gov.br/sintegra/sintegra1",
                "Content-Length: " . strlen(http_build_query($data)),
                "Cookie: CAKEPHP={$cookie["CAKEPHP"]}; path=/sintegra"
            ],
        ];

        curl_setopt_array($curl, $curlOptions);

        $response = curl_exec($curl);

        if ($response == false)
        {
            echo "ERRO! requisição da informações da empresa" . PHP_EOL . curl_error($curl);
            exit();
        }

        if ($this->validationsRequest($response, $captcha, $cnpj))
        {
            preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $response,  $match);
            parse_str($match[1][1], $cookie);

            return $this->getInformations($response, $cookie);
        }

        return null;
    }

    private function validationsRequest($response, $captcha, $cnpj)
    {
        $cnpj = (int)str_replace([".", "/", "-"], "", $cnpj);

        preg_match_all('/^Location:\s(.*)/mi', $response,  $match);

        if (!empty($match[1]))
        {
            $errorUrl = trim($match[1][0]);

            if (strpos($errorUrl, $captcha))
            {
                echo "Captcha digitado é inválido.";
                return false;
            }

            if (strpos($errorUrl, 'Inscri%C7%C3o+CNPJ+Inv%C1lida'))
            {
                echo "CNPJ informado é inválido.";
                return false;
            }
        }

        return true;
    }

    private function getInformations($response, $cookie)
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML($response);
        libxml_clear_errors();

        $valuesI = $this->getValuesI($dom);
        $valuesA = $this->getValuesA($dom);
        $valuesC = $this->getValuesC($dom);

        $return[] = array_merge($valuesI, $valuesA, $valuesC);

        $return[] = $this->getNextInput($dom, $cookie);

        return $return;
    }

    private function getValuesI($dom)
    {
        $domXPath = new DOMXPath($dom);

        $index = [
            'cnpj' => 'CNPJ:',
            'ie' => 'Inscrição Estadual:',
            'razao_social' => 'Nome Empresarial:'
        ];

        $content = [];
        $return = [];

        foreach ($domXPath->query("//*[@id='Sintegra1ConsultarForm']/table[2]") as $table)
        {
            foreach ($table->childNodes as $tbody)
            {
                foreach ($tbody->childNodes as $key => $tr)
                {
                    foreach ($tr->childNodes as $td)
                    {
                        $content[] = $td->textContent;
                    }
                }
            }
        }

        $return = $this->adjustArray($index, $content);

        return $return;
    }

    private function getValuesA($dom)
    {
        $domXPath = new DomXPath($dom);

        $index = [
            'logradouro' => 'Logradouro:',
            'numero' => 'Número:',
            'complemento' => 'Complemento:',
            'bairro' => 'Bairro:',
            'municipio' => 'Município:',
            'uf' => 'UF:',
            'cep' => 'CEP:',
            'telefone' => 'Telefone:',
            'email' => 'E-mail:'
        ];

        $content = [];
        $return = [];

        foreach ($domXPath->query("//*[@id='Sintegra1ConsultarForm']/table[4]") as $table)
        {
            foreach ($table->childNodes as $tbody)
            {
                foreach ($tbody->childNodes as $tr)
                {
                    foreach ($tr->childNodes as $td)
                    {
                        $content[] = $td->textContent;
                    }
                }
            }
        }

        $return = $this->adjustArray($index, $content);

        return $return;
    }

    private function getValuesC($dom)
    {
        $domXPath = new DomXPath($dom);

        $index = [
            'atividade_principal' => 'Atividade Econômica Principal:',
            'data_inicio' => 'Início das Atividades:',
            'situacao_atual' => 'Situação Atual:'
        ];

        $content = [];
        $return = [];

        foreach ($domXPath->query("//*[@id='Sintegra1ConsultarForm']/table[6]") as $tabela)
        {
            foreach ($tabela->childNodes as $tbody)
            {
                foreach ($tbody->childNodes as $tr)
                {
                    foreach ($tr->childNodes as $td)
                    {
                        $content[] = $td->textContent;
                    }
                }
            }
        }

        $return = $this->adjustArray($index, $content);

        $mainActivity = explode("-", $return['atividade_principal']);
        $situation = explode("-", $return['situacao_atual']);

        if (isset($mainActivity[1]))
        {
            $return['atividade_principal'] = ['codigo' => $mainActivity[0], 'descricao' => trim($mainActivity[1])];
        }
        else
        {
            $return['atividade_principal'] = ['codigo' => $mainActivity[0], 'descricao' => 'Não informada'];
        }

        if (isset($situation[0]))
        {
            $return['situacao_atual'] = trim($situation[0]);
        }
        else
        {
            $return['situacao_atual'] = 'Não informada';
        }

        $return['hora'] = date("H:i:s");
        $return['data'] = date("d/m/Y");

        return $return;
    }

    private function getNextInput($dom, $cookie)
    {
        $domXPath = new DomXPath($dom);

        $token = "";

        $nextInput = $domXPath->query('//*[@id="Sintegra1CampoAnterior"]/attribute::value');

        if (sizeof($nextInput))
        {
            $token = $nextInput->item(0)->nodeValue;
        }

        if (!$token) return;

        $data = [
            "_method" => "POST",
            "data[Sintegra1][campoAnterior]" => $token,
            "consultar" => ""
        ];

        $curl = curl_init();

        $curlOptions = [
            CURLOPT_URL => $this->urlSintegraNextInput,
            CURLOPT_POST => 1,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_HEADER => 1,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/x-www-form-urlencoded",
                "Cookie: CAKEPHP={$cookie["CAKEPHP"]}; path=/sintegra; Domain=www.sintegra.fazenda.pr.gov.br"
            ],
        ];

        curl_setopt_array($curl, $curlOptions);

        $response = curl_exec($curl);

        if ($response == false)
        {
            echo "ERRO! requisição de novos valores" . PHP_EOL . curl_error($curl);
            exit();
        }

        return $this->getInformations($response, $cookie);
    }

    private function adjustArray($index, $content)
    {
        $content = (array)$content;

        $return = [];

        foreach ($index as $tag => $campo)
        {
            $key = array_search($campo, $content);

            $value = ($key !== false && isset($content[$key + 1])) ? $content[$key + 1] : null;

            $isInput = in_array($value, $index);

            $return[$tag] = $isInput ? null : trim($value);
        }

        return $return;
    }
}
