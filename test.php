<?php

require_once "crawler/WebCrawler.php";

use crawler\WebCrawler;

echo "----- EXECUTANDO -----" . PHP_EOL;

$crawler = new WebCrawler();

/*
 * Pega o CNPJ para buscar as IEs e formata
 */
echo "Informe um CNPJ que queira pegar as IEs (somante números): ";
$cnpj = trim(fgets(STDIN));
$cnpjFormatted = $crawler->formatCnpj($cnpj);

$response = $crawler->searchCnpj($cnpjFormatted);

function printValues($data, $indent = 0)
{
    foreach ($data as $key => $value)
    {
        if (is_array($value))
        {
            if (!empty($value))
            {
                echo str_repeat("  ", $indent) . "$key:" . PHP_EOL;
                printValues($value, $indent + 1);
            }
        }
        else
        {
            echo str_repeat("  ", $indent) . "$key: $value" . PHP_EOL;
        }
    }
}

foreach ($response as $index => $item)
{
    echo "$index:" . PHP_EOL;
    printValues($item, 1);
    echo PHP_EOL;
}

echo "----- FINALIZANDO -----";
