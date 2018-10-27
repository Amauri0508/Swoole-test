<?php
$client = new swoole_client(SWOOLE_SOCK_TCP);
if (!$client->connect('127.0.0.1', 9501, -1))
{
    exit("connect failed. Error: {$client->errCode}\n");
}
//$client->send("insert into `test` (`name`) values ('chen2');");
$client->send("select * from test;");
echo $client->recv();
$client->close();