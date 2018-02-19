<?php

namespace Sfadless\Payment\Bank;

use GuzzleHttp\Client;
use Sfadless\Payment\Card;
use Sfadless\Payment\Exception\BankAccessException;
use Sfadless\Payment\Exception\BankRequestException;
use Sfadless\Payment\Exception\TransactionNotFoundException;
use Sfadless\Payment\Transaction\AbstractTransaction;
use Sfadless\Payment\Transaction\Transaction;
use Sfadless\Payment\Transaction\TransactionInterface;
use Sfadless\Payment\Transaction\TransactionResult;


/**
 * AlfaBank
 *
 * @author Pavel Golikov <pgolikov327@gmail.com>
 */
class AlfaBank implements BankInterface
{
    const API_URL = 'https://engine.paymentgate.ru/payment/rest/';

    private $successCode = 0;

    private $createdCodes = [-100];

    private $returnUrl;

    /**
     * @var Client
     */
    private $client;

    /**
     * @var string
     */
    private $login;

    /**
     * @var string
     */
    private $password;

    /**
     * @param string $transactionId
     * @return AbstractTransaction|TransactionInterface
     * @throws BankAccessException
     * @throws BankRequestException
     * @throws TransactionNotFoundException
     */
    public function getTransactionById($transactionId)
    {
        $response = $this->request('getOrderStatusExtended.do', ['orderId' => $transactionId]);
        return $response;
        if (isset($response['errorCode']) && $response['errorCode'] == 6) {
            throw new TransactionNotFoundException($transactionId);
        }

        $transaction = $this->extractTransactionFromArray($response);

        return $transaction;
    }

    public function updateTransaction(TransactionInterface $transaction)
    {
        // TODO: Implement updateTransaction() method.
    }

    /**
     * @param array $array
     * @return AbstractTransaction
     */
    private function extractTransactionFromArray(array $array)
    {
        $transaction = new Transaction();

        if (isset($array['cardAuthInfo']) && is_array($array['cardAuthInfo'])) {
            $card = $this->extractCardFromArray($array['cardAuthInfo']);

            $transaction->setCard($card);
        }

        if (isset($array['ip'])) {
            $transaction->setIp($array['ip']);
        }

        $transaction
            ->setBank($this)
            ->setTransactionId($array['attributes'][0]['value'])
            ->setCost($array['amount'])
            ->setOrderNumber($array['orderNumber'])
            ->setDescription(isset($array['orderDescription']) ? $array['orderDescription'] : '')
            ->setCreatedDatetime((new \DateTime())->setTimestamp($array['date'] / 1000))
            ->setResult($this->extractResultFromArray($array))
        ;

        return $transaction;
    }

    /**
     * @param array $array
     * @return Card
     */
    private function extractCardFromArray(array $array)
    {
        $card = new Card();

        $card
            ->setNumber($array['pan'])
            ->setHolder($array['cardholderName'])
            ->setExpiration($array['expiration'])
        ;

        return $card;
    }

    /**
     * @param array $array
     *
     * @return TransactionResult
     */
    private function extractResultFromArray(array $array)
    {
        $result = new TransactionResult();

        $result
            ->setCode($array['actionCode'])
            ->setDescription($array['actionCodeDescription'])
        ;

        if (isset($array['authDateTime'])) {
            $result->setPayedDate((new \DateTime())->setTimestamp((int) $array['authDateTime'] / 1000));
        }

        if (isset($array['paymentAmountInfo']['approvedAmount']) && $array['paymentAmountInfo']['approvedAmount'] > 0) {
            $result->setPayedAmount((int) $array['paymentAmountInfo']['approvedAmount']);
        }

        switch (true) {
            case $this->successCode === $array['actionCode'] : return $result->setStatus(TransactionResult::PAYED);
            case in_array($array['actionCode'], $this->createdCodes) : return $result->setStatus(TransactionResult::CREATED);

            default : return $result->setStatus(TransactionResult::ERROR);
        }
    }

    /**
     * @param string $orderNumber
     * @param string $description
     * @param int $cost
     * @return TransactionInterface
     * @throws BankAccessException
     * @throws BankRequestException
     */
    public function createTransaction($orderNumber, $description, $cost)
    {
        $response = $this->request('register.do', [
            'orderNumber' => $orderNumber,
            'description' => $description,
            'amount' => $cost,
            'returnUrl' => $this->returnUrl,
        ]);

        if (isset($response['errorCode']) && $response['errorCode'] == 1) {
            throw new BankRequestException($response['errorMessage']);
        }

        $transaction = new Transaction();

        $transaction
            ->setBank($this)
            ->setCreatedDatetime(new \DateTime())
            ->setDescription($description)
            ->setUrl($response['formUrl'])
            ->setTransactionId($response['orderId'])
            ->setOrderNumber($orderNumber)
            ->setCost($cost)
        ;

        return $transaction;
    }

    /**
     * @param $method
     * @param $data
     * @return mixed
     * @throws BankAccessException
     * @throws BankRequestException
     */
    private function request($method, $data)
    {
        $data = array_merge($data, [
            'userName' => $this->login,
            'password' => $this->password
        ]);

        $response = (string) $this->client->request('POST', $method, [
            'form_params' => $data
        ])->getBody();

        $decoded = json_decode($response, true);

        if (!$decoded) {
            throw new BankRequestException('Неверный формат ответа из банка');
        }

        if (isset($decoded['errorCode']) && 5 == $decoded['errorCode']) {
            throw new BankAccessException($decoded['errorMessage']);
        }

        return $decoded;
    }

    /**
     * AlfaBank constructor.
     * @param $login string
     * @param $password string
     * @param array $options
     */
    public function __construct($login, $password, array $options = [])
    {
        if (!is_string($login) || !is_string($password) || strlen($login) === 0 || strlen($password) === 0) {
            throw new \InvalidArgumentException('Неверный формат логина или пароля');
        }

        $this->client = new Client(['base_uri' => static::API_URL]);
        $this->login = $login;
        $this->password = $password;

        if (isset($options['returnUrl']) && strlen('returnUrl') > 0) {
            $this->returnUrl = $options['returnUrl'];
        }
    }
}