<?php

namespace Moneta;

use Moneta;

class MonetaSdkMethods
{
	const EXCEPTION_NO_CONNECTION_TYPE      = 'no connection type is defined: ';

    const EXCEPTION_NO_MOTHOD               = 'method is not exists: ';

    const EXCEPTION_MONETA                  = 'merchantAPI error: ';

    const INCORRECT_INPUT_DATA              = 'incorrect input data: ';

    const EXCEPTION_INCORRECT_AMOUNT        = 'incorrect amount: ';

    const EXCEPTION_MONETA_METHOD           = 'unable to execute method: ';

    public $settings;

    private $monetaConnectionType;

    public $monetaService;

    public $storageService;

    public $data;

    public $render;

    public $error;

    public $errorCode;

    public $errorMessage;

    public $errorMessageHumanConverted;

    public $events;

    public $calledMethods;


    /**
     * Execute SDK method
     *
     * @param $function
     * @param $args
     * @throws MonetaSdkException
     */
    public function executeSdkRequest($function, $args)
    {
        if (!method_exists($this->monetaService, $function)) {
            $this->error = true;
            throw new MonetaSdkException(self::EXCEPTION_NO_MOTHOD . $function);
        }

        try {
            $result = call_user_func_array(array($this->monetaService, $function), $args);
            $response = json_decode(json_encode($result), true);

            if ($this->getSettingValue('monetasdk_debug_mode')) {
                MonetaSdkUtils::addToLog("executeSdkRequest:\n" . print_r($response, true));
            }

            if ($this->monetaConnectionType == 'json' && isset($response['Envelope']['Body']['fault'])) {
                // error is detected (json)
                $this->parseJsonException($response['Envelope']['Body']['fault']);
                throw new MonetaSdkException(self::EXCEPTION_MONETA . $function. " " . $this->errorMessage);
            }
            else {
                $this->data = $response;
                $this->render = MonetaSdkUtils::requireView($function, $this->data, $this->getSettingValue('monetasdk_view_files_path'));
            }
        }
        catch (\Exception $e) {
            // error is detected (soap)
            $this->parseSoapException($e);
            throw new MonetaSdkException(self::EXCEPTION_MONETA . $function. " " . $this->errorMessage);
        }
    }

    /**
     * Create connection with Moneta if necessary
     *
     * @throws MonetaSdkException
     */
	public function checkMonetaServiceConnection()
	{
		if (!$this->monetaService) {
			$this->monetaConnectionType = $this->getSettingValue('monetasdk_connection_type');
            $cert       = $this->getSettingValue('monetasdk_use_x509') ? $this->getSettingValue('monetasdk_x509_pem_file') : "";
            if ($cert && !file_exists($cert)) {
                $this->setSettingValue('monetasdk_use_x509', false);
            }

			if ($this->monetaConnectionType == 'soap') {
				$wsdl 		= $this->getSettingValue('monetasdk_demo_mode') ? $this->getSettingValue('monetasdk_demo_url') : $this->getSettingValue('monetasdk_production_url');
				if ($this->getSettingValue('monetasdk_use_x509')) {
					$wsdl  .= $this->getSettingValue('monetasdk_x509_port') ? ":".$this->getSettingValue('monetasdk_x509_port') : "";
				}
				$wsdl      .= $this->getSettingValue('monetasdk_use_x509') ? $this->getSettingValue('monetasdk_x509_soap_link') : $this->getSettingValue('monetasdk_soap_link');
				$username	= $this->getSettingValue('monetasdk_account_username');
				$password	= $this->getSettingValue('monetasdk_account_password');
				$options 	= null;
				$isDebug	= $this->getSettingValue('monetasdk_debug_mode');

				// connect to moneta wsdl
				$this->monetaService = new MonetaSdkSoapConnector($wsdl, $username, $password, $cert, $options, $isDebug);
			}
			else if ($this->monetaConnectionType == 'json') {
                $jsonConnectionUrl       = $this->getSettingValue('monetasdk_demo_mode') ? $this->getSettingValue('monetasdk_demo_url') : $this->getSettingValue('monetasdk_production_url');
                if ($this->getSettingValue('monetasdk_use_x509')) {
                    $jsonConnectionUrl  .= $this->getSettingValue('monetasdk_x509_port') ? ":".$this->getSettingValue('monetasdk_x509_port') : "";
                }
                $jsonConnectionUrl      .= $this->getSettingValue('monetasdk_use_x509') ? $this->getSettingValue('monetasdk_x509_json_link') : $this->getSettingValue('monetasdk_json_link');
                $username	= $this->getSettingValue('monetasdk_account_username');
                $password	= $this->getSettingValue('monetasdk_account_password');
                $isDebug	= $this->getSettingValue('monetasdk_debug_mode');

                // connect to moneta json service
                $this->monetaService = new MonetaSdkJsonConnector($jsonConnectionUrl, $username, $password, $cert, $isDebug);
			}
			else {
                $this->error = true;
				throw new MonetaSdkException(self::EXCEPTION_NO_CONNECTION_TYPE . $this->monetaConnectionType);
			}
		}
	}

    /**
     * Clean SDK method
     */
    public function cleanResultData()
    {
        $this->data                         = null;
        $this->render                       = null;
        $this->error                        = false;
        $this->errorCode                    = null;
        $this->errorMessage                 = null;
        $this->errorMessageHumanConverted   = null;
    }

    /**
     * @param $value
     * @return mixed
     * @throws MonetaSdkException
     */
    public function getSettingValue($value)
    {
        return MonetaSdkUtils::getValueFromArray($value, $this->settings);
    }

    /**
     * @param $name
     * @param $value
     */
    public function setSettingValue($name, $value)
    {
        $this->settings[$name] = $value;
    }

    /**
     * @param null $payer
     * @param $payee
     * @param $amount
     * @param $orderId
     * @param string $paymentSystem
     * @param bool|false $isRegular
     * @param null $additionalData
     * @return int
     * @throws MonetaSdkException
     */
    public function sdkMonetaCreateInvoice($payer = null, $payee, $amount, $orderId, $paymentSystem = 'payanyway', $isRegular = false, $additionalData = null)
    {
        $transactionId = 0;
        $createInvoiceResult = $this->pvtMonetaCreateInvoice($payer, $payee, $amount, $orderId, $paymentSystem, $isRegular, $additionalData);

        if (is_object($createInvoiceResult)) {
            $transactionId = $createInvoiceResult->transaction;
        }
        else if (is_array($createInvoiceResult) && isset($createInvoiceResult['transaction'])) {
            $transactionId = $createInvoiceResult['transaction'];
        }
        else {
            throw new MonetaSdkException(self::EXCEPTION_MONETA . 'sdkMonetaCreateInvoice: no transactionId received');
        }
        MonetaSdkUtils::handleEvent('InvoiceCreated', array('transactionId' => $transactionId, 'amount' => $amount, 'paymentSystem' => $paymentSystem), $this->getSettingValue('monetasdk_event_files_path'));
        return $transactionId;
    }

    /**
     * @param $fromAccountId
     * @param $toAccountId
     * @param $amount
     * @param null $clientTransaction
     * @param $attributes
     * @param null $description
     * @return bool
     * @throws MonetaSdkException
     */
    public function sdkMonetaPayment($fromAccountId, $toAccountId, $amount, $clientTransaction = null, $attributes, $description = null)
    {
        $res = false;
        try
        {
            $payment = new \Moneta\Types\PaymentRequest();
            $payment->amount = number_format($amount, 2, '.', '');;
            // откуда перечисляем
            $payment->payer = $fromAccountId;
            // куда перечисляем
            $payment->payee = $toAccountId;
            // эта сумма снимается с плетельщика
            $payment->isPayerAmount = true;
            if ($description) {
                $payment->description = $description;
            }
            if (is_array($attributes) && count($attributes)) {
                $operationInfo = new \Moneta\Types\OperationInfo();
                foreach ($attributes AS $key => $value) {
                    $operationInfo->addAttribute($this->pvtMonetaCreateAttribute($key, $value));
                }
                $operationInfo->addAttribute($this->pvtMonetaCreateAttribute('customurlparameters', http_build_query($attributes)));
            }
            $payment->operationInfo = $operationInfo;
            if ($clientTransaction) {
                $payment->clientTransaction = $clientTransaction;
            }

            $res = $this->monetaService->Payment($payment);
        }
        catch (Exception $e)
        {
            throw new MonetaSdkException(self::EXCEPTION_MONETA . 'sdkPutSecretToAccountProfile: ' . print_r($e, true));
        }

        return $res;
    }

    /**
     * @param $accountId
     * @return int
     * @throws MonetaSdkException
     */
    public function sdkMonetaGetAccountBalance($accountId)
    {
        $balance = 0;
        try
        {
            $monetaAccount = $this->monetaService->FindAccountById($accountId);
            if ($monetaAccount) {
                $monetaAccount = json_decode(json_encode($monetaAccount, true));
                if (isset($monetaAccount->account->balance)) {
                    $balance = $monetaAccount->account->balance;
                }
            }
        }
        catch (Exception $e)
        {
            $this->error = true;
            throw new MonetaSdkException(self::EXCEPTION_MONETA . 'sdkMonetaGetAccountBalance: ' . print_r($e, true));
        }

        return $balance;
    }

    /**
     * @param $operationId
     * @return array|bool|mixed|null|object
     * @throws MonetaSdkException
     */
    public function sdkMonetaGetOperationDetailsById($operationId)
    {
        $result = false;
        try {
            $operationInfo = $this->GetOperationDetailsById($operationId);
            $result = json_decode(json_encode($operationInfo, true));
        }
        catch (Exception $e)
        {
            $this->error = true;
            throw new MonetaSdkException(self::EXCEPTION_MONETA . 'sdkMonetaGetOperationDetailsById: ' . print_r($e, true));
        }

        return $result;
    }

    /**
     * @param $secret
     * @return array|bool|mixed|null|object
     * @throws MonetaSdkException
     */
    public function sdkPutSecretToAccountProfile($secret)
    {
        $result = false;
        try {
            $unitId = null;
            $profileResult = $this->monetaFindProfileInfoByAccountId($this->getSettingValue('monetasdk_account_id'));
            if (isset($profileResult->data)) {
                $data = $profileResult->data;
                if (isset($data['profile']['attribute'])) {
                    $attributes = $data['profile']['attribute'];
                    $unitId = MonetaSdkUtils::getValueFromMonetaAttributes($attributes, 'unitid');
                }
            }
            if (!$unitId) {
                throw new MonetaSdkException(self::EXCEPTION_MONETA_METHOD . 'sdkPutSecretToAccountProfile');
            }

            $result = $this->sdkMonetaUpdateUserSecret($unitId, $secret);
        }
        catch (Exception $e)
        {
            $this->error = true;
            throw new MonetaSdkException(self::EXCEPTION_MONETA . 'sdkPutSecretToAccountProfile: ' . print_r($e, true));
        }

        return $result;
    }

    /**
     * @return bool
     * @throws MonetaSdkException
     */
    public function sdkGetSecretFromAccountProfile()
    {
        $result = false;
        try {
            $profileResult = $this->monetaFindProfileInfoByAccountId($this->getSettingValue('monetasdk_account_id'));
            if (isset($profileResult->data)) {
                $data = $profileResult->data;
                if (isset($data['profile']['attribute'])) {
                    $attributes = $data['profile']['attribute'];
                    $result = MonetaSdkUtils::getValueFromMonetaAttributes($attributes, 'customfield:secret');
                }
            }
        }
        catch (Exception $e)
        {
            $this->error = true;
            throw new MonetaSdkException(self::EXCEPTION_MONETA . 'monetaFindProfileInfoByAccountId: ' . print_r($e, true));
        }

        return $result;
    }

    /**
     * @param $firstName
     * @param $lastName
     * @param $email
     * @param $gender
     * @return bool
     * @throws MonetaSdkException
     */
    public function sdkMonetaCreateUser($firstName, $lastName, $email, $gender)
    {
        $unitId = false;
        try
        {
            if (!$firstName || !$lastName || !preg_match('/^[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,9}$/i', $email)) {
                throw new MonetaSdkException(self::EXCEPTION_INCORRECT_INPUT_DATA . 'sdkMonetaCreateUser');
            }
            if (!in_array($gender, array('MALE', 'FEMALE'))) {
                $gender = 'MALE';
            }

            $profile = new \Moneta\Types\Profile();
            $profile->addAttribute($this->pvtMonetaCreateAttribute('first_name',                $firstName));
            $profile->addAttribute($this->pvtMonetaCreateAttribute('last_name',                 $lastName));
            $profile->addAttribute($this->pvtMonetaCreateAttribute('email_for_notifications',   $email));
            $profile->addAttribute($this->pvtMonetaCreateAttribute('sex',                       $gender));

            $monetaProfile = new \Moneta\Types\CreateProfileRequest();
            if ($this->getSettingValue('monetasdk_prototype_user_unit_id')) {
                $monetaProfile->unitId = $this->getSettingValue('monetasdk_prototype_user_unit_id');
            }

            $monetaProfile->profileType = \Moneta\Types\ProfileType::client;
            $monetaProfile->profile = $profile;

            $unitId = $this->monetaService->CreateProfile($monetaProfile);
            if (!$unitId) {
                throw new MonetaSdkException(self::EXCEPTION_MONETA_METHOD . 'sdkMonetaCreateUser');
            }
        }
        catch (Exception $e)
        {
            $this->error = true;
            throw new MonetaSdkException(self::EXCEPTION_MONETA . 'sdkMonetaCreateUser: ' . print_r($e, true));
        }

        return $unitId;
    }

    /**
     * @param $unitId
     * @param $firstName
     * @param $lastName
     * @param $email
     * @param $gender
     * @return bool
     * @throws MonetaSdkException
     */
    public function sdkMonetaUpdateUser($unitId, $firstName, $lastName, $email, $gender)
    {
        $result = false;
        try
        {
            if (!$firstName || !$lastName || !preg_match('/^[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,9}$/i', $email)) {
                throw new MonetaSdkException(self::EXCEPTION_INCORRECT_INPUT_DATA . 'sdkMonetaCreateUser');
            }
            if (!in_array($gender, array('MALE', 'FEMALE'))) {
                $gender = 'MALE';
            }

            $profile = new \Moneta\Types\Profile();
            $profile->addAttribute($this->pvtMonetaCreateAttribute('first_name',                $firstName));
            $profile->addAttribute($this->pvtMonetaCreateAttribute('last_name',                 $lastName));
            $profile->addAttribute($this->pvtMonetaCreateAttribute('email_for_notifications',   $email));
            $profile->addAttribute($this->pvtMonetaCreateAttribute('sex',                       $gender));

            $monetaProfile = new \Moneta\Types\EditProfileRequest();
            $monetaProfile->unitId = $unitId;
            $monetaProfile->profile = $profile;

            $this->monetaService->EditProfile($monetaProfile);
            $result = true;
        }
        catch (Exception $e)
        {
            $this->error = true;
            throw new MonetaSdkException(self::EXCEPTION_MONETA . 'sdkMonetaCreateUser: ' . print_r($e, true));
        }

        return $result;
    }

    /**
     * @param $unitId
     * @param $secret
     * @return bool
     * @throws MonetaSdkException
     */
    public function sdkMonetaUpdateUserSecret($unitId, $secret)
    {
        $result = false;
        try
        {
            if (!$secret) {
                throw new MonetaSdkException(self::EXCEPTION_INCORRECT_INPUT_DATA . 'sdkMonetaUpdateUserSecret');
            }

            $profile = new \Moneta\Types\Profile();
            $profile->addAttribute($this->pvtMonetaCreateAttribute('customfield:secret', $secret));

            $monetaProfile = new \Moneta\Types\EditProfileRequest();
            $monetaProfile->unitId = $unitId;
            $monetaProfile->profile = $profile;

            $this->monetaService->EditProfile($monetaProfile);
            $result = true;
        }
        catch (Exception $e)
        {
            $this->error = true;
            throw new MonetaSdkException(self::EXCEPTION_MONETA . 'sdkMonetaUpdateUserSecret: ' . print_r($e, true));
        }

        return $result;
    }

    /**
     * @param $unitId
     * @param $paymentPassword
     * @param $alias
     * @return bool
     * @throws MonetaSdkException
     */
    public function sdkMonetaCreateAccount($unitId, $paymentPassword, $alias)
    {
        $accountId = false;
        try
        {
            $monetaAccount = new \Moneta\Types\CreateAccountRequest();
            $monetaAccount->currency = \Moneta\Types\Currency::RUB;
            $monetaAccount->unitId              = $unitId;
            $monetaAccount->paymentPassword     = $paymentPassword;
            $monetaAccount->alias               = $alias;
            if ($this->getSettingValue('monetasdk_prototype_user_account_id')) {
                $monetaAccount->prototypeAccountId = $this->getSettingValue('monetasdk_prototype_user_account_id');
            }
            $accountId = $this->monetaService->CreateAccount($monetaAccount);
            if (!$accountId) {
                throw new MonetaSdkException(self::EXCEPTION_MONETA_METHOD . 'sdkMonetaCreateAccount');
            }
            $handleCreateAccount = MonetaSdkUtils::handleEvent('CreateAccountResult', array('unitId' => $unitId, 'accountId' => $accountId, 'paymentPassword' => $paymentPassword, 'alias' => $alias));
        }
        catch (Exception $e)
        {
            $this->error = true;
            throw new MonetaSdkException(self::EXCEPTION_MONETA . 'sdkMonetaCreateAccount: ' . print_r($e, true));
        }

        return $accountId;
    }

    /**
     * @param $fromAccountId
     * @param $fromAccountPaymentPassword
     * @param $toAccountId
     * @param $amount
     * @param string $description
     * @return array|bool|mixed|null|object
     * @throws MonetaSdkException
     */
    public function sdkMonetaTransfer($fromAccountId, $fromAccountPaymentPassword = null, $toAccountId, $amount, $description = '')
    {
        $result = false;
        try
        {
            if ($amount <= 0) {
                throw new MonetaSdkException(self::EXCEPTION_INCORRECT_AMOUNT . 'sdkMonetaTransfer');
            }

            $amount = number_format($amount, 2, '.', '');

            if (!$fromAccountPaymentPassword) {
                $secret = $this->sdkGetSecretFromAccountProfile();
                $fromAccountPaymentPassword = MonetaSdkUtils::decrypt($this->getSettingValue('monetasdk_account_pay_password_enrypted'), $secret);
            }

            $monetaTransfer = new \Moneta\Types\TransferRequest();
            $monetaTransfer->payer              = $fromAccountId;
            $monetaTransfer->paymentPassword    = $fromAccountPaymentPassword;
            $monetaTransfer->payee              = $toAccountId;
            $monetaTransfer->amount             = $amount;
            $monetaTransfer->description        = $description;
            $monetaTransfer->isPayerAmount      = true;

            $result = json_decode(json_encode($this->monetaService->Transfer($monetaTransfer), true));
            if (!$result) {
                throw new MonetaSdkException(self::EXCEPTION_MONETA_METHOD . 'sdkMonetaTransfer');
            }
        }
        catch (Exception $e)
        {
            $this->error = true;
            throw new MonetaSdkException(self::EXCEPTION_MONETA . 'sdkMonetaTransfer: ' . print_r($e, true));
        }

        return $result;
    }

    /**
     * @param $accountId
     * @param $dateFrom
     * @param $dateTo
     * @param int $itemsPerPage
     * @param int $pageNumber
     * @return bool
     * @throws MonetaSdkException
     */
    public function sdkMonetaHistory($accountId, $dateFrom, $dateTo, $itemsPerPage = 20, $pageNumber = 1)
    {
        $history = false;
        try
        {
            $filter = new \Moneta\Types\FindOperationsListRequestFilter();
            $filter->accountId  = $accountId;
            $filter->dateFrom   = $dateFrom;
            $filter->dateTo     = $dateTo;

            $pager = new \Moneta\Types\Pager();
            $pager->pageNumber  = $pageNumber;
            $pager->pageSize    = $itemsPerPage;

            $request = new \Moneta\Types\FindOperationsListRequest();
            $request->filter = $filter;
            $request->pager = $pager;

            $history = $this->monetaService->FindOperationsList($request);
            if (!$history) {
                throw new MonetaSdkException(self::EXCEPTION_MONETA_METHOD . 'sdkMonetaHistory');
            }

            $history = json_decode(json_encode($history, true));
        }
        catch (Exception $e)
        {
            $this->error = true;
            throw new MonetaSdkException(self::EXCEPTION_MONETA . 'sdkMonetaHistory: ' . print_r($e, true));
        }

        return $history;
    }

    /**
     * @param $operationId
     * @return bool
     * @throws MonetaSdkException
     */
    public function sdkMonetaPayRecurrent($operationId, $description = null)
    {
        $result = false;
        try
        {
            $amount = 0;
            $getOperationToken = null;
            $getOperationStatus = null;
            $fromAccountId = null;
            $customParameters = null;

            $getOperationResult = $this->monetaService->GetOperationDetailsById($operationId);
            if (is_object($getOperationResult) && isset($getOperationResult->operation) && is_object($getOperationResult->operation)) {
                // если у операции есть paymenttoken -> сохраним его в найденный счёт в колонку paymentToken
                $getOperationResultAttributes = $getOperationResult->operation->attribute;
                if (count($getOperationResultAttributes) && is_array($getOperationResultAttributes)) {
                    foreach ($getOperationResultAttributes AS $oneAttribute) {
                        if (is_object($oneAttribute) && isset($oneAttribute->key) && $oneAttribute->key == 'paymenttoken') {
                            $getOperationToken = $oneAttribute->value;
                        }
                        if (is_object($oneAttribute) && isset($oneAttribute->key) && $oneAttribute->key == 'statusid') {
                            $getOperationStatus = $oneAttribute->value;
                        }
                        if (is_object($oneAttribute) && isset($oneAttribute->key) && $oneAttribute->key == 'targetaccountid') {
                            $fromAccountId = $oneAttribute->value;
                        }
                        if (!$description && is_object($oneAttribute) && isset($oneAttribute->key) && $oneAttribute->key == 'description') {
                            $description = $oneAttribute->value;
                        }
                        if (is_object($oneAttribute) && isset($oneAttribute->key) && $oneAttribute->key == 'sourceamount') {
                            $amount = $oneAttribute->value;
                            if ($amount < 0) {
                                $amount = (-1) * $amount;
                            }
                        }
                        if (is_object($oneAttribute) && isset($oneAttribute->key) && $oneAttribute->key == 'customurlparameters') {
                            $customParameters = $oneAttribute->value;
                        }
                    }
                }
            }
            $customParametersArray = null;
            if ($customParameters) {
                parse_str($customParameters, $customParametersArray);
            }
            $baseAttributes = array('PAYMENTTOKEN' => $getOperationToken, 'MNT_DUPLICATE_ID' => $operationId);
            $attributes = ($customParametersArray && is_array($customParametersArray) && count($customParametersArray) > 0) ? array_merge($baseAttributes, $customParametersArray) : $baseAttributes;
            if ($getOperationToken != null && $getOperationStatus == 'SUCCEED') {
                $result = $this->sdkMonetaPayment($fromAccountId, $this->getSettingValue('monetasdk_account_id'), $amount, str_replace('.', '', trim(microtime(true))).rand(1, 99), $attributes, $description);
            }
        }
        catch (Exception $e)
        {
            $this->error = true;
            throw new MonetaSdkException(self::EXCEPTION_MONETA . 'sdkMonetaPayRecurrent: ' . print_r($e, true));
        }

        return $result;
    }

    /**
     * Create new invoice
     *
     * @param null $payer
     * @param $payee
     * @param $amount
     * @param $transactionId
     * @param bool|false $isRegular
     * @param $additionalData
     * @return mixed
     * @throws MonetaSdkException
     */
    private function pvtMonetaCreateInvoice($payer = null, $payee, $amount, $transactionId, $paymentSystem = 'payanyway', $isRegular = false, $additionalData = null)
    {
        try
        {
            $invoiceRequest = new \Moneta\Types\InvoiceRequest();
            if ($payer) {
                $invoiceRequest->payer = $payer;
            }

            $invoiceRequest->payee = $payee;
            $invoiceRequest->amount = $amount;
            $invoiceRequest->clientTransaction = $transactionId;

            $operationInfo = new \Moneta\Types\OperationInfo();
            if ($isRegular) {
                $operationInfo->addAttribute($this->pvtMonetaCreateAttribute('PAYMENTTOKEN',        'request'));
            }
            if ($paymentSystem == 'post' && $additionalData && $this->checkAdditionalData($paymentSystem, $additionalData)) {
                $operationInfo->addAttribute($this->pvtMonetaCreateAttribute('mailofrussiaindex',   $additionalData['additionalParameters_mailofrussiaSenderIndex']));
                $operationInfo->addAttribute($this->pvtMonetaCreateAttribute('mailofrussiaregion',  $additionalData['additionalParameters_mailofrussiaSenderRegion']));
                $operationInfo->addAttribute($this->pvtMonetaCreateAttribute('mailofrussiaaddress', $additionalData['additionalParameters_mailofrussiaSenderAddress']));
                $operationInfo->addAttribute($this->pvtMonetaCreateAttribute('mailofrussianame',    $additionalData['additionalParameters_mailofrussiaSenderName']));
            }
            else if ($paymentSystem == 'euroset' && $additionalData && $this->checkAdditionalData($paymentSystem, $additionalData)) {
                $operationInfo->addAttribute($this->pvtMonetaCreateAttribute('rapidamphone',   $additionalData['additionalParameters_rapidaPhone']));
            }

            $invoiceRequest->operationInfo = $operationInfo;
            $invoiceResponse = $this->monetaService->Invoice($invoiceRequest);
            if ($this->monetaConnectionType == 'json' && isset($invoiceResponse['Envelope']['Body']['fault'])) {
                $e = $invoiceResponse['Envelope']['Body']['fault'];
                throw new MonetaSdkException(self::EXCEPTION_MONETA . 'pvtMonetaCreateInvoice: ' . print_r($e, true));
            }
            else {
                return $invoiceResponse;
            }
        }
        catch (Exception $e)
        {
            $this->error = true;
            throw new MonetaSdkException(self::EXCEPTION_MONETA . 'pvtMonetaCreateInvoice: ' . print_r($e, true));
        }
    }

    /**
     * @param string $paymentSystem
     * @return array
     */
    public function getAdditionalFieldsByPaymentSystem($paymentSystem = 'payanyway')
    {
        $result = array();

        switch ($paymentSystem) {
            case 'post':
                $result = array('additionalParameters_mailofrussiaSenderIndex', 'additionalParameters_mailofrussiaSenderRegion', 'additionalParameters_mailofrussiaSenderAddress', 'additionalParameters_mailofrussiaSenderName');
                break;
            case 'euroset':
                $result = array('additionalParameters_rapidaPhone');
                break;
        }

        return $result;
    }

    /**
     * @param $var
     * @param null $source
     * @return null
     */
    public function getRequestedValue($var, $source = null)
    {
        $value = null;
        if ((!$source || strtolower($source) == 'post') && isset($_POST[$var])) {
            $value = $_POST[$var];
        }
        if ((!$source || strtolower($source) == 'get') && isset($_GET[$var])) {
            $value = $_GET[$var];
        }

        return $value;
    }

    /**
     * @param $var
     * @return null|string
     */
    public function getRequestedValueSource($var)
    {
        $source = null;
        if (isset($_POST[$var])) {
            $source = 'post';
        }
        if (isset($_GET[$var])) {
            $source = 'get';
        }

        return $source;
    }

    /**
     * @param $vars
     * @param null $source
     * @return array
     */
    public function addAdditionalData($vars, $source = null)
    {
        $result = array();
        foreach ($vars AS $var) {
            $value = null;
            if ((!$source || strtolower($source) == 'post') && isset($_POST[$var])) {
                $value = $_POST[$var];
            }
            if ((!$source || strtolower($source) == 'get') && isset($_GET[$var])) {
                $value = $_GET[$var];
            }

            $result[$var] = array("var" => $var, "value" => $value, "name" => null);
        }

        return $result;
    }

    /**
     * @return bool|string
     */
    public function detectEventTypeFromVars()
    {
        $detectedEvent = false;
        if ($this->isFieldsSet(array('choosePaySysByType'))) {
            $detectedEvent = 'ForwardChoosePaymentSystemForm';
        }
        if ($this->isFieldsSet(array('moneta_sdk_first_name', 'moneta_sdk_last_name', 'moneta_sdk_email', 'moneta_sdk_gender'))) {
            $detectedEvent = 'ForwardCreateUserForm';
        }
        if ($this->isFieldsSet(array('moneta_sdk_account', 'moneta_sdk_date_from', 'moneta_sdk_date_to'))) {
            $detectedEvent = 'ForwardAccountHistoryForm';
        }
        if ($this->isFieldsSet(array('MNT_ID', 'MNT_TRANSACTION_ID', 'MNT_AMOUNT'))) {
            $detectedEvent = 'ForwardPaymentForm';
        }
        if ($this->isFieldsSet(array('MNT_ID', 'MNT_TRANSACTION_ID', 'MNT_OPERATION_ID', 'MNT_AMOUNT', 'MNT_CURRENCY_CODE', 'MNT_TEST_MODE', 'MNT_SIGNATURE'))) {
            $detectedEvent = 'MonetaSendCallBack';
        }
        if ($this->isFieldsSet(array('mode', 'hash', 'email'))) {
            $detectedEvent = 'CancelRegularPayment';
        }

        return $detectedEvent;
    }

    /**
     * @return array
     */
    public function getInternalEventNames()
    {
        return array('ForwardAccountHistoryForm', 'ForwardCreateUserForm', 'ForwardChoosePaymentSystemForm', 'ForwardPaymentForm', 'MonetaSendCallBack', 'CancelRegularPayment');
    }

    /**
     * @return bool|string
     */
    public function renderError()
    {
        $data = array('error' => $this->error, 'errorCode' => $this->errorCode, 'errorMessage' => $this->errorMessage, 'errorMessageHumanConverted' => $this->errorMessageHumanConverted);
        $renderResult = MonetaSdkUtils::requireView('ErrorMessage', $data, $this->getSettingValue('monetasdk_view_files_path'));
        $this->render = $renderResult;

        return $renderResult;
    }

    /**
     * @return mixed
     */
    public function getStorageService()
    {
        if (!$this->storageService) {
            if ($this->getSettingValue('monetasdk_storage_type')) {
                $storageSettings = array('monetasdk_storage_files_path' => $this->getSettingValue('monetasdk_storage_files_path'), 'monetasdk_storage_mysql_host' => $this->getSettingValue('monetasdk_storage_mysql_host'),
                    'monetasdk_storage_mysql_username' => $this->getSettingValue('monetasdk_storage_mysql_username'), 'monetasdk_storage_mysql_password' => $this->getSettingValue('monetasdk_storage_mysql_password'),
                    'monetasdk_storage_mysql_port' => $this->getSettingValue('monetasdk_storage_mysql_port'), 'monetasdk_storage_mysql_database' => $this->getSettingValue('monetasdk_storage_mysql_database'));

                $storageServiceName = "\\Moneta\\MonetaSdk" . ucfirst($this->getSettingValue('monetasdk_storage_type')) . "Storage";
                $storageServiceObject = new $storageServiceName($storageSettings);
                $this->storageService = $storageServiceObject;
            }
            else {
                $this->storageService = new \Moneta\MonetaSdkEmptyStorage();
            }
        }

        return $this->storageService;
    }

    /**
     * @param $vars
     * @return bool
     */
    private function isFieldsSet($vars, $source = null)
    {
        $result = true;
        foreach ($vars AS $var) {
            if (strtolower($source) == 'post' && !isset($_POST[$var])) {
                $result = false;
            }
            if (strtolower($source) == 'get' && !isset($_GET[$var])) {
                $result = false;
            }
            if (!$source && !isset($_POST[$var]) && !isset($_GET[$var])) {
                $result = false;
            }
        }

        return $result;
    }

    /**
     * @param $vars
     * @return bool
     */
    private function isFieldsUnset($vars, $source = null)
    {
        // no any field is in request of defined source type
        $result = true;
        foreach ($vars AS $var) {
            if ((!$source || strtolower($source) == 'post') && isset($_POST[$var])) {
                $result = false;
            }
            if ((!$source || strtolower($source) == 'get') && isset($_GET[$var])) {
                $result = false;
            }
        }

        return $result;
    }

    /**
     * @param $key
     * @param $value
     * @return MonetaKeyValueAttribute
     */
    private function pvtMonetaCreateAttribute($key, $value)
    {
        $monetaAtribute = new \Moneta\Types\KeyValueAttribute();
        $monetaAtribute->key = $key;
        $monetaAtribute->value = $value;

        return $monetaAtribute;
    }

    /**
     * @param string $paymentSystem
     * @param $additionalData
     * @return bool
     */
    private function checkAdditionalData($paymentSystem = 'payanyway', $additionalData)
    {
        $result = true;

        switch ($paymentSystem) {
            case 'post':
                $result = $this->checkParamsInArray($additionalData, $this->getAdditionalFieldsByPaymentSystem($paymentSystem));
                break;
            case 'euroset':
                $result = $this->checkParamsInArray($additionalData, $this->getAdditionalFieldsByPaymentSystem($paymentSystem));
                break;
        }

        return $result;
    }

    /**
     * @param $inputData
     * @param $params
     * @return bool
     * @throws MonetaSdkException
     */
    private function checkParamsInArray($inputData, $params)
    {
        $result = true;
        if (!count($params) || !is_array($params)) {
            $result = false;
        }
        foreach ($params AS $param) {
            if (!MonetaSdkUtils::getValueFromArray($param, $inputData)) {
                $result = false;
            }
        }

        return $result;
    }

    /**
     * @param $e
     */
    private function parseSoapException($e)
    {
        $this->error = true;

        if (is_object($e)) {
            $e = (array) $e;
        }

        if ($this->monetaConnectionType == 'soap') {
            if (isset($e['detail']) && is_object($e['detail'])) {
                $this->errorCode = $e['detail']->faultDetail;
            }
            if (isset($e['faultstring'])) {
                $this->errorMessage = $e['faultstring'];
            }
            if ($this->errorCode && isset($this->settings[$this->errorCode])) {
                $this->errorMessageHumanConverted = $this->settings[$this->errorCode];
            }
            else {
                $this->errorMessageHumanConverted = $this->errorMessage;
                $handleServiceUnavailableEvent = MonetaSdkUtils::handleEvent('ServiceUnavailable', array('errorCode' => $this->errorCode, 'errorMessage' => $this->errorMessage, 'errorMessageHumanConverted' => $this->errorMessageHumanConverted), $this->getSettingValue('monetasdk_event_files_path'));
            }
        }

    }

    /**
     * @param $data
     */
    private function parseJsonException($data)
    {
        $this->error = true;

        if ($this->getSettingValue('monetasdk_debug_mode')) {
            MonetaSdkUtils::addToLog("parseJsonException:\n" . print_r($data, true));
        }

        if (isset($data['detail']['faultDetail'])) {
            $this->errorCode = $data['detail']['faultDetail'];
        }
        if (isset($data['faultstring'])) {
            $this->errorMessage = $data['faultstring'];
        }
        if ($this->errorCode && isset($this->settings[$this->errorCode])) {
            $this->errorMessageHumanConverted = $this->settings[$this->errorCode];
        }
        else {
            $this->errorMessageHumanConverted = $this->errorMessage;
            $handleServiceUnavailableEvent = MonetaSdkUtils::handleEvent('ServiceUnavailable', array('errorCode' => $this->errorCode, 'errorMessage' => $this->errorMessage, 'errorMessageHumanConverted' => $this->errorMessageHumanConverted), $this->getSettingValue('monetasdk_event_files_path'));
        }

    }

}
