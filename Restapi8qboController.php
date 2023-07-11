<?php
/**
 * Created by PhpStorm.
 * User:
 * Date: 12/01/2020
 * Time: 19:56
 */

require_once(ROOTPATH . '/../app/extensions/components/AuthMiddleware.php');
require_once(ROOTPATH . '/../app/extensions/components/ValidationHelper.php');
require_once(ROOTPATH . '/../app/extensions/components/Request.php');

require_once 'vendor/autoload.php';

use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Constraints as Assert;

//use QuickBooksOnline\API\DataService\DataService;
//use QuickBooksOnline\API\Core\Http\Serialization\XmlObjectSerializer;
//use QuickBooksOnline\API\Facades\Invoice;
//use QuickBooksOnline\API\Facades\Account;
//
//use QuickBooksOnline\API\Core\ServiceContext;
//use QuickBooksOnline\API\PlatformService\PlatformService;

class Restapi8qboController extends ApiController {

    private $configs;
    private $access_token;
    private $refresh_token;
    private $realmId;
    private $client;
    private $companyInfo;
    private $appId;
    private $companyObjectId;
    private $companyObjectData;
    private $newInvoiceStatus;
    private $partPaidStatus;
    private $newInvoiceScreenId;
    private $Api8;
    private $firstSync;
    private $listNameForData247;
    private $Data247ListName;
    private $listId;
    private $columnNameForPhone;

    /**
     * Create and initialize the controller
     */

    public function __construct()
    {

        //        if ((Yii::app()->params['client_portal']) !== constant('qboTenant')) {
        //            return false;
        //        }

        $this->configs = constant('qboConfig');
        $this->newInvoiceScreenId = constant('qboConfigScreenId');
        $this->newInvoiceStatus = constant('qboConfigStatusIdOpenInvoice');
        $this->partPaidStatus = constant('qboConfigStatusIdPartPaid');
        $this->appId = constant('qboConfigAppId');
        $this->listNameForData247 = constant('qboListForData247');
        $this->columnNameForPhone = 'phone';

        if (!headers_sent()) {
            header('X-Frame-Options: DENY');
            header("strict-transport-security: max-age=600");
        }

        $MBST_SERVER_TEMP = getenv('MBST_SERVER', true) ? : getenv('MBST_SERVER');
        if (($MBST_SERVER_TEMP == "XYZ_DEV") and (rand(1, 30) == 1)) {// to "fast" memcache on local machine with proxy
            Yii::app()->cache->flush();
            $memcache_obj = new Memcached();
            $memcache_obj->addServer('mbst_memcached', 11211);
            $memcache_obj->flush(1);
        }

        if (session_status() === PHP_SESSION_NONE || session_status() != PHP_SESSION_ACTIVE) {
            try {
                //session_name('bToMSessId');
                $domain = str_ireplace('https://', '', constant('qboHost'));
                session_set_cookie_params(1800, "/", $domain, true, true);
                session_start();
                if (isset($_SERVER['REQUEST_URI']) and
                    stripos($_SERVER['REQUEST_URI'], 'oauth/qbo/callback') == false
                ) {
                    $actual_link = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") .
                        "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
                    $_SESSION["actual_link"] = $actual_link;
                }
            } catch (Error $error) {
                Yii::log($error->getMessage(), 'error');

            }
        }

        $this->Api8 = new Restapi8Controller("");
        $this->firstSync = false;
    }

    /**
     * find and return regex template string by name
     *
     * @param string $assertName
     * @param array $options
     *
     * @return Assert\Regex
     */
    public function getDefaultRegexAssert($assertName, $options = null)
    {
        return ValidationHelper::getDefaultRegexAssert($assertName, $options);
    }

    /**
     * Qbo getting OAuth2 tokens and realmId
     *
     * @return array
     */
    private function getQboTokenInSession()
    {

        $configs = $this->configs;

        $authorizationRequestUrl = $configs['authorizationRequestUrl'];
        $tokenEndPointUrl = $configs['tokenEndPointUrl'];

        $scope = $configs['oauth_scope'];
        $redirect_uri = $configs['oauth_redirect_uri'];

        $response_type = 'code';
        $state = 'RandomState';
        $include_granted_scope = 'false';
        $grant_type = 'authorization_code';
        //$certFilePath = './Certificate/all.platform.intuit.com.pem';
        //'./Certificate/cacert.pem'
        $certFilePath = null;

        if (!isset($_GET["code"])) {

            $authUrl = $this->client->getAuthorizationURL(
                $authorizationRequestUrl, $scope, $redirect_uri, $response_type, $state);
            header("Location: " . $authUrl, true, 302);
            exit();
        } else {
            $code = $_GET["code"];
            $responseState = $_GET['state'];
            if (strcmp($state, $responseState) != 0) {
                throw new Exception("The state is not correct from Intuit Server. Consider your app is hacked.");
            }
            $result = $this->client->getAccessToken($tokenEndPointUrl, $code, $redirect_uri, $grant_type);

            //go to first link
            //window.opener.location.href = window.opener.location.href;

            if ((rand(1, 30) == 1) or (get_class(Yii::app()) == 'CConsoleApplication'))  {
                header("Location: " . ($_SESSION["actual_link"] ?? (constant('qboHost') . '/entryPoint')), true, 302);
            }
            else {
                echo ' <script type="text/javascript"> window.setInterval(function () {
                    try {
                         window.open(\'' . ($_SESSION["actual_link"] ?? (constant('qboHost') . '/entryPoint')) . '\',"_self");
                    } catch (e) {
                        console.log(e)
                    }
                }, 3000);</script> ';

                require_once(constant('pathBill2meTamplateLogin'));
            }


            //            echo '<script type="text/javascript">
            //                var win = window.open(\'' . $_SESSION["actual_link"] . '\',"_self");
            //                window.close();
            //                </script>';

        }

        return $result;
    }

    /**
     * Show info by company of object
     *
     *
     */

    public function actionGetCompanyInfo()
    {
        $this->qboCallbackOAuth2(Yii::app()->db);//have to be executed before every call to qbo server
        $this->sendResponse(200, $this->companyInfo);

    }

    /**
     * print tarif selector
     *
     * @param EDbConnection $connection
     *
     *
     * @return string
     */

    private function PrintTarifCheck($connection)
    {
        require_once(constant('pathBill2meTamplateTable'));
    }

    /**
     * print qr and link
     *
     * @param EDbConnection $connection
     *
     *
     * @return string
     */

    private function printLinks($connection)
    {
        $sl = $this->getQboShortLink();
        ?>
      <a data-aos="fade-left"
         id="abcHrefImg">
        <img class="is-rounded"
             src="/api/v8/qbo/qrImg"
             alt=""
             href="<?php echo $sl; ?>" />
      </a>
      <a data-aos="fade-in"
         class="is-rounded left-link"
         id="abcHref"
         href="<?php echo $sl; ?>"> <?php echo $sl; ?></a>
        <?php
    }

    /**
     * print field object new_invocie_text
     *
     * @param EDbConnection $connection
     *
     *
     * @return string
     */

    private function PrintTextInvoice($connection)
    {
        //print($this->companyObjectId);
        //print $connection->createCommand(
        //    'select "new_invocie_text" from objects o where o.id = '. $this->companyObjectId)
        //    ->queryScalar();
        print $this->companyObjectData['new_invocie_text'];
    }

    /**
     * return select if this weekday set for object
     *
     * @param int $day
     *
     *
     * @return string
     */
    function setSelected($day)
    {
        //$setedDay = $connection->createCommand(
        //    'select "weekDayForRemember" from objects o where o.id = ' . $this->companyObjectId)
        //    ->queryScalar();

        return intval($day) === intval($this->companyObjectData['weekDayForRemember']) ? ' selected' : '';
    }

    /**
     * print allowed count day without payment
     *
     *
     * @return string
     */
    function printDayWithoutPay()
    {
        print intval($this->companyObjectData['waitDayBeforeRemember'] ?? 1);
    }

    /**
     * print field object remider_text
     *
     * @param EDbConnection $connection
     *
     *
     * @return string
     */

    private function PrintTextReminder($connection)
    {
        //print($this->companyObjectId);
        //print $connection->createCommand(
        //    'select "remider_text" from objects o where o.id = '. $this->companyObjectId)
        //    ->queryScalar();
        $str = $this->companyObjectData['remider_text'];
        $str = preg_replace('/[^A-Za-z0-9. -]/', '', $str);
        print ($str);
    }

    /**
     * calculate and rrint Tarif Table
     *
     * @param EDbConnection $connection
     *
     *
     * @return string
     */

    private function PrintTarifTables($connection)
    {
        if (!isset($this->companyObjectId)) {
            return false;
        }
        $dateTimeNow = (new DateTime());
        $dateTimeNowAndMounts = (new DateTime());
        Utils::shiftMonthsDate(6, $dateTimeNowAndMounts, "Y-m-d", true);

        $dateModified = new DateTime($this->companyObjectData['DateCreate']);
        $iteration = 0;
        $mountlyFee = 'free';
        $totalCost = 'free';
        $res = 0;

        do {
            $monthStart = $dateModified->format("Y-m-d") . ' 00:00:00';
            Utils::shiftMonthsDate(1, $dateModified, "Y-m-d", true);
            $monthEnd = $dateModified->format("Y-m-d") . ' 00:00:00';
            $MonthEnding[] = ' <p class="subheading">' . $dateModified->format("d M 'y") . '</p>';
            $TotalCostOfFree10Service[] = ' <p class="subheading"> 10 </p>';

            if ($dateModified > $dateTimeNow) {
                $res = '...';
                $currentPlan = '...';
                $mountlyFee = '...';
                $totalCost = '...';
            } else {
                if ($iteration++ > 0) {

                    $res = '-';
                    $currentPlan = '-';
                    $mountlyFee = '-';
                    $totalCost = '-';
                    $billId = $connection->createCommand(
                        'select "BackendId" from backendext 
                        where "ColumnName" = \'monthEnd\' and "ColumnValue" = \'' . $monthEnd . '\' and "BackendId" in (select id from backend o 
                        where "ActionName" = :ActionName and "ObjectId" = :ObjectId and "StatusId" = :StatusId)')
                        ->queryScalar(
                            [
                                ':ActionName' => constant('qboEventNameForBill'),
                                ':StatusId' => constant('qboConfigStatusIdClosedInvoice'),
                                ':ObjectId' => $this->companyObjectId,
                            ]);
                    if ($billId) {
                        $billAttributes = $connection->createCommand(
                            'select * from backendext where "BackendId" = ' . $billId)
                            ->queryAll(true);

                        foreach ($billAttributes as $billAttribute) {
                            switch ($billAttribute['ColumnName']) {
                                case 'currentPlan'  :
                                    $mountlyFee = explode('/', $billAttribute['ColumnValue'])[0];
                                    break;
                                case 'totalCost'  :
                                    $totalCost = $billAttribute['ColumnValue'] . '$';
                                    break;
                                case 'countOfInvoices'  :
                                    $res = $billAttribute['ColumnValue'];
                                    break;
                                default  :
                                    $currentPlan = '';
                                    break;
                            }
                        }
                    }
                } else {
                    $res = 'free';
                    array_pop($TotalCostOfFree10Service);
                    $TotalCostOfFree10Service[] = ' <p class="subheading"> &#8734; </p>';
                }
            }

            $MonthlyServiceFee[] = ' <p class="subheading">' . $mountlyFee . '</p>';
            $TotalCostOfService[] = ' <p class="subheading">' . $totalCost . '</p>';
            $NumberOfSentInvoices[] = ' <p class="subheading">' . $res . '</p>';

        } while ($dateModified < $dateTimeNowAndMounts);
        $resStr = '';
        foreach ($MonthEnding as $i => $MonthEndingVal) {
            $resStr .= '<tr><td class="a1" >' . $MonthEnding[$i] . '</td><td class="a2" >' . $MonthlyServiceFee[$i] .
                '</td><td class="a3">' . $NumberOfSentInvoices[$i] . '</td><td class="a4" >' . $TotalCostOfService[$i] .
                '</td></tr>';
        }
        require_once(constant('pathBill2meTamplateTable2'));
    }

    /**
     * set id of current object
     *
     * @param int $objectId
     */

    //    public function setCompanyObjectId($objectId)
    //    {
    //        $this->companyObjectId = $objectId;
    //
    //    }

    /**
     * Example of allowed queries in qbo server
     *
     * @return array
     */

    public function getCompanyInfo()
    {

        $url = constant('qboUrlApi') . '/company/' . $this->realmId . '/companyinfo/' . $this->realmId;
        //'query?query=select * from CompanyInfo'
        //    .'&minorversion=45';
        return $this->client->callForApiEndpointGet($url, $this->access_token);

    }

    /**
     * Check if possible company already exist in app
     *
     * @param EDbConnection $connection
     * @param array $alreadyExistAuth
     * @param array $objectData
     *
     * @return array
     */
    private function checkCompanyExist($connection, $alreadyExistAuth, &$objectExist)
    {

        $fieldsForSearsh = [
            'Email' => $this->companyInfo['CompanyInfo']['Email']['Address'] ?? '',
            'LegalName' => $this->companyInfo['CompanyInfo']['LegalName'] ?? '',//not ever? name could be changed
            //probably have to be checked by qboId
        ];

        if (isset($alreadyExistAuth['ObjectId']) and intval($alreadyExistAuth['ObjectId']) > 0) {
            $fieldsForSearsh = [
                'id' => intval($alreadyExistAuth['ObjectId']),
            ];
        }

        $set = $this->genSetWhereMultipart($fieldsForSearsh);

        $where = preg_replace('/^where\s/', '', $set['whereText']);
        //dd($alreadyExistAuth, $objectExist, $where, $set);
        $objectExist = $connection->createCommand()//check existing this company in hole database
        ->select('id')
            ->from('objects')
            ->where(
                $where, $set['params'])
            ->queryRow();

        $objectExistTemp = $objectExist['id'] ?? null;

        if ($objectExistTemp) {
            $objectExist = $connection->createCommand()//check existing this company in hole database
            ->select(
                'id, DateCreate, CompanyName, LegalName, selectedPaidPlan, waitDayBeforeRemember, weekDayForRemember, 
                new_invocie_text, remider_text, disabled')
                ->from('objects')
                ->where(
                    $where, $set['params'])
                ->queryRow();

            if (!$this->firstSync) {
                $this->firstSync = $connection->createCommand()
                ->select('id')
                    ->from('objects')
                    ->where(
                        ' ("DateCreate" > (NOW() - INTERVAL \'5 minutes\')) and id = ' . $objectExist['id'])
                    ->queryScalar();
            }

        } else {
            return null;
        }

        return $objectExist['id'] ?? null;
    }

    /**
     * Qbo getting OAuth2 tokens and realmId
     *
     * @param EDbConnection $connection
     *
     * @return array
     */
    private function CreateCurrentCompany($connection)
    {
        $params['fields'] = $this->companyInfo['CompanyInfo'];
        $params['fields']['ApplicationId'] = $this->appId;
        $params['fields']['qboId'] = $params['fields']['Id'];
        $params['fields']['Enabled'] = 1;

        $params['fields']['new_invocie_text'] = constant('qboNewInvoiceText');
        $params['fields']['remider_text'] = constant('qboReminderText');
        $params['fields']['selectedPaidPlan'] = 1;
        $params['fields']['disabled'] = 0;

        unset($params['fields']['Email']);
        $params['fields']['Email'] = $this->companyInfo['CompanyInfo']['Email']['Address'] ?? '';
        unset($params['fields']['Id']);
        $res = $this->Api8->createObject($connection, $params, 0);
        $this->firstSync = true;

        return $res['res']['body']['data'][0]['id'] ?? false;
    }

    /**
     *
     *
     *
     */

    private function redirectSelf()
    {
        $configs = $this->configs;
        header('Location: ' . $configs['oauth_redirect_uri'], true, 302);
        die();
    }

    /**
     * save token to object
     *
     * @param EDbConnection $connection
     * @param int $objectId,
     *
     * @return array
     */

    private function setQboToken($connection, $objectId)
    {
        $result = $this->getQboTokenInSession();

        if (!isset($result['access_token']) || !isset($result['refresh_token'])) {
            $this->redirectSelf();
        }
        $result['timeStamp'] = date('Y-m-d H:i:s');

        if (isset($_GET['realmId'])) {
            $result['realmId'] = $_GET['realmId'];
        }

        $fields = [
            'ApplicationId' => $_GET['appId'] ?? 1,
            'ObjectId' => $objectId ?? 0,
            'APIApplicationId' => $_GET['apiAppId'] ?? 1,
            'AccessToken' => $result['access_token'],
            'RefreshToken' => $result['refresh_token'],
            'Params' => json_encode($result),
        ];
        $insert = $this->genInsertMultipart($fields);

        //$res = $connection->createCommand()
        //->delete('oauthtokens', '"RefreshToken" = :refresh_token', [':refresh_token' => $result['refresh_token']]);

        $connection->createCommand(
            'insert into oauthtokens' . $insert['fields'] . ' ' . $insert['values'])
            ->execute($insert['params']);

        if (isset($result['realmId'])) {
            $fields['realmId'] = $result['realmId'];
        }

        return $fields;
    }

    /**
     * refresh token by Oauth2 protocol
     *
     * @param EDbConnection $connection
     * @param array $alreadyExistAuth
     *
     * @return array
     */

    private function refreshQboToken($connection, $alreadyExistAuth)
    {
        $refreshToken = $alreadyExistAuth['RefreshToken'];
        $configs = $this->configs;
        $grant_type = 'refresh_token';
        $result = $this->client->refreshAccessToken(
            $configs['tokenEndPointUrl'], $grant_type, $refreshToken);
        $result['timeStamp'] = date('Y-m-d H:i:s');

        if (isset($_GET['realmId'])) {
            $result['realmId'] = $_GET['realmId'];
        } else {
            $params = json_decode($alreadyExistAuth['Params'], true);
            if (is_array($params) and isset($params['realmId'])) {
                $result['realmId'] = $params['realmId'];
            }

        }

        if (!isset($result['access_token']) or !isset($result['access_token'])) {

            if ($alreadyExistAuth['ObjectId']) {
                //get access
                $tableName = 'objects';
                $params = [
                    'disabled' => 1,
                ];
                $connection->createCommand()
                    ->update(
                        $tableName, $params, ' "id"  = ' . intval($alreadyExistAuth['ObjectId']));
            }

            if (get_class(Yii::app()) == 'CConsoleApplication') {
                return [];
            }

            session_unset();
            if (!headers_sent()) {
                exit(header("Location: " . constant('qboHost') . "/entryPoint", true, 302));
            }
            exit();
        }

        $fields = [
            'ApplicationId' => $_GET['appId'] ?? 1,
            'APIApplicationId' => $_GET['apiAppId'] ?? 1,
            'AccessToken' => $result['access_token'],
            'RefreshToken' => $result['refresh_token'],
            'Params' => json_encode($result),
        ];
        $set = $this->genSetWhereMultipart($fields);
        $connection->createCommand(
            'update oauthtokens ' . $set['setText'] . ' where "RefreshToken" = \'' . $refreshToken . '\'')
            ->execute($set['params']);
        $fields['ObjectId'] = $objectId ?? 0;

        if (isset($result['realmId'])) {
            $fields['realmId'] = $result['realmId'];
        }

        return $fields;
    }

    /**
     * auth procedure for OAuth2 protocol
     *
     *
     */

    public function actionQboGetOrRefreshToken()
    {
        //do not return the result in to front, never
        $this->qboCallbackOAuth2(Yii::app()->db);//have to be executed before every call to qbo server
        $this->sendResponse(200, 'success refresh');
    }


    /**
     * disconnect company from service
     *
     * return void
     */
    public function actionQboDisconnecting()
    {
        try {

            $connection = Yii::app()->db;
            $this->qboCallbackOAuth2($connection);
            $res = $this->disconnectService($connection);

        } catch (Error $error) {
            $res = $this->catchAnyThrowable($error, 400, $error->getMessage(), $error->getMessage(), false);
        }

        $this->sendResponse(200, 'success refresh');
    }


    /**
     *
     * set column disabled as 1
     *
     * @param EDbConnection $connection
     *
     * return void
     *
     */
    public function disconnectService($connection)
    {
        $tableName = 'objects';
        $params = [
            'disabled' => 1,
        ];
        $connection->createCommand()
            ->update(
                $tableName, $params, ' "id"  = ' . intval($this->companyObjectId));

    }


    /**
     * connect company from service
     *
     * return void
     */
    public function actionQboConnecting()
    {
        try {

            $connection = Yii::app()->db;
            $this->qboCallbackOAuth2($connection);
            $res = $this->connectservice($connection);

        } catch (Error $error) {
            $res = $this->catchAnyThrowable($error, 400, $error->getMessage(), $error->getMessage(), false);
        }

        $this->sendResponse(200, 'success refresh');
    }


    /**
     *
     * set column disabled as 0
     *
     * @param EDbConnection $connection
     *
     * return void
     */
    public function connectService($connection)
    {
        $tableName = 'objects';
        $params = [
            'disabled' => 0,
        ];
        $connection->createCommand()
            ->update(
                $tableName, $params, ' "id"  = ' . intval($this->companyObjectId));


    }

    /**
     * special procedure for complete authorisation by OAuth2 protocol
     *
     * @param EDbConnection $connection
     * @param int $objectId
     * @param boolean $move_to_session
     * @param boolean $createCompanyIfNecessary
     * @param boolean $updateCompanyIfNecessary
     * return array
     */
    public function qboCallbackOAuth2(
        $connection,
        $objectId = null,
        $move_to_session = true,
        $createCompanyIfNecessary = false,
        $updateCompanyIfNecessary = false
    )//have to be executed before every call to qbo server
    {
        $configs = $this->configs;
        //dd($configs,$objectToken,$alreadyExistAuth);
        //try to get old auth
        $alreadyExistAuth = null;
        //dd($_SESSION); print_r($_SESSION);
        if (isset($objectId)) {
            // $this->companyObjectId = $objectId;
        }
        if (isset($objectId) and !isset($_SESSION['refresh_token']) and !isset($_SESSION['access_token'])) {
            //if we try to come as some one object
            //$objectId =  ?? null;
            $params = [
                'objectId' => $objectId,
            ];
            $alreadyExistAuth = $connection->createCommand()
                ->select('*')
                ->from('oauthtokens')
                ->where(
                    '"ObjectId" = :objectId and not ("AccessToken" is null) ', $params)
                ->queryRow();
            $_SESSION['refresh_token'] = $alreadyExistAuth['RefreshToken'];
        }

        if (isset($_SESSION['refresh_token'])) {//if we have completed auth
            $params = [
                'RefreshToken' => $_SESSION['refresh_token'],
            ];

            $alreadyExistAuth = $connection->createCommand()
                ->select('*')
                ->from('oauthtokens')
                ->where(
                    '"RefreshToken" = :RefreshToken', $params)
                ->queryRow();
        }

        if (isset($_SESSION['access_token'])) {//if we have completed auth
            $params = [
                'AccessToken' => $_SESSION['access_token'],
            ];

            $alreadyExistAuth = $connection->createCommand()
                ->select('*')
                ->from('oauthtokens')
                ->where(
                    '"AccessToken" = :AccessToken', $params)
                ->queryRow();
        }


        $this->client = new QboOAuthProvider($configs['client_id'], $configs['client_secret']);
        $objectToken = [];
        if (empty($alreadyExistAuth)) {
            if (get_class(Yii::app()) == 'CConsoleApplication') {// my be it necessary add to RestApi8qboController
                print('object has not token ');

                return false;
            }
            $objectToken = $this->setQboToken($connection, $objectId);
        } else {
            if (strlen($alreadyExistAuth['RefreshToken']) > 0) {
                $objectToken = $this->refreshQboToken($connection, $alreadyExistAuth);
            }
        }

        if (isset($objectToken['AccessToken']) and isset($objectToken['RefreshToken'])) {
            if ($move_to_session) {
                unset($_SESSION['access_token']);
                unset($_SESSION['refresh_token']);
                $_SESSION['access_token'] = $objectToken['AccessToken'];
                $_SESSION['refresh_token'] = $objectToken['RefreshToken'];
            }
            $this->access_token = $objectToken['AccessToken'];
            $this->refresh_token = $objectToken['RefreshToken'];
        } else {
            if (!$createCompanyIfNecessary) {
                if (get_class(Yii::app()) == 'CConsoleApplication') {
                    return false;
                }
                $this->sendResponse(400, 'token for yor company not found');
            }
        }
        //dd($objectToken);
        if (isset($objectToken['realmId'])) {
            $this->realmId = $objectToken['realmId'];
        } else {
            if (!$createCompanyIfNecessary) {
                if (get_class(Yii::app()) == 'CConsoleApplication') {
                    return false;
                }
                $this->sendResponse(400, 'token for yor company not found');
            }
        }

        //now we have to check and create if non exist object for this (or new) company
        $this->companyInfo = $this->getCompanyInfo();

        if(!($this->companyInfo) or !isset($this->companyInfo['CompanyInfo'])){

            if ($objectId) {
                //revoke access
                $tableName = 'objects';
                $params = [
                    'disabled' => 1,
                ];
                $connection->createCommand()
                    ->update(
                        $tableName, $params, ' "id"  = ' . intval($objectId));
            }

            if (get_class(Yii::app()) == 'CConsoleApplication') {
                return false;
            }

            session_unset();
            if (!headers_sent()) {
                exit(header("Location: " . constant('qboHost') . "/disconnectPoint", true, 302));
            }
            //header("Location: ./disconnectPoint", true, 302);
            //header("Location: ./startPoint", true, 302);А
            //$this->sendResponse(400, 'yor company not found');
            exit();
        }

        $this->companyObjectId = $this->checkCompanyExist($connection, $alreadyExistAuth, $objectCompanyData);
        $this->companyObjectData = $objectCompanyData;

        if (($alreadyExistAuth['ObjectId'] !== $this->companyObjectId) and (intval($this->companyObjectId)>0)) {
            $fields = [
                'ObjectId' => $this->companyObjectId,
            ];

            $set = $this->genSetWhereMultipart($fields);

            //            $connection->createCommand(//if we whant to crash authorisation on other devices
            //                'delete from oauthtokens where "ObjectId" = ' . $this->companyObjectId . ' ')
            //                ->execute();

            $connection->createCommand(
                'update oauthtokens ' . $set['setText'] . ' where "RefreshToken" = \'' . $this->refresh_token . '\'')
                ->execute($set['params']);
        }

//        if (!$this->companyObjectId and !$createCompanyIfNecessary) {
//            $this->sendResponse(400, 'object for yor company not exist');
//        } //we will create it every time

        if (!$this->companyObjectId) {
            $this->checkAndCreateColumns($connection, $this->companyInfo['CompanyInfo']);
            $this->companyObjectId = $this->CreateCurrentCompany($connection);
        } else {

            if ($updateCompanyIfNecessary) {
                if (($objectCompanyData['SyncToken'] ?? '') != $this->companyInfo['CompanyInfo']['SyncToken']) {

                    $params = $this->companyInfo['CompanyInfo'];
                    $params['qboId'] = $params['Id'];
                    unset($params['Id']);
                    unset($params['Email']);
                    $params['Email'] = $this->companyInfo['CompanyInfo']['Email']['Address'] ?? '';
                    $params['id'] = $this->companyObjectId;
                    //$params['Phone'] = $this->companyObjectId;
                    $this->checkAndCreateColumns($connection, $params);

                    $affected = $this->Api8->updateObject(
                        $params, $this->companyObjectId, $this->appId);

                    //dd(1, $params, $affected, $this);
                }
            }


        }

        if ($this->companyObjectId) {

            $this->connectService($connection);
            $connection->createCommand()
                ->delete(
                    'oauthtokens', '"ObjectId" = 0 or "ObjectId" is null or "AccessToken" is null');

            if ($this->refresh_token) {
                $connection->createCommand()
                    ->delete(
                        'oauthtokens', '"ObjectId" = :id and "RefreshToken"<>:rt', [
                        'id' => $this->companyObjectId,
                        'rt' => $this->refresh_token,
                    ]);
            }

        }

        return ($objectToken);


    }

    /**
     * check if customer already exist
     *
     * @param EDbConnection $connection
     * @param array $fields
     * return array
     */
    private function checkCustomerExist($connection, $fields)
    {
        //dd($fields);
        $fieldsForSearsh = [
            'Email' => $fields['Email'] ?? '',
            'ParentObject' => $fields['ParentObject'] ?? '',
            //i planed the discussion with Viktor and Alex about it
        ];

        $set = $this->genSetWhereMultipart($fieldsForSearsh);

        $where = preg_replace('/^where\s/', '', $set['whereText']);

        $objectExist = $connection->createCommand()//check existing this company in hole database
        ->select('*')
            ->from('objects')
            ->where(
                $where, $set['params'])
            ->queryRow();

        return $objectExist;
    }

    /**
     * not completed procedure, only for the first customer
     *
     *
     */
    public function actionSyncAllCustomers()
    {

        $connection = Yii::app()->db;
        $this->qboCallbackOAuth2($connection);
        $res = ($this->syncCustomerByQboId(Yii::app()->db, 1));
        $this->sendResponse(200, ['objectId' => $res]);
        //dd($this->syncAllCustomers($connection));

    }

    /**
     * create coustomer if it necessary
     *
     * @param EDbConnection $connection
     * @param array $params
     * @param int $id
     * @param int $applicationId
     *
     * return int
     */
    public function updateObject($connection, $params, $id, $applicationId)
    {
        $constraint = new Assert\Collection(
            [
                'objectId' => [
                    new ObjectId($applicationId),
                ],
                'applicationId' => [
                    $this->getDefaultRegexAssert('positive_integer'),
                    new Assert\NotBlank(),
                ],
            ]);

        $paramsToValidate = [
            'objectId' => $id,
            'applicationId' => $applicationId,
        ];

        $validateResult = $this->globalValidator($paramsToValidate, $constraint);
        if (is_array($validateResult)) {
            return $validateResult;
        }

        unset($params['applicationId']);

        try {

            $update = $this->genSetWhereMultipart($params);

            //$this->beforeUpdateObject($update);

            return $connection->createCommand('update objects ' . $update['setText'] . ' where id = ' . $id)
                ->execute($update['params']);

        } catch (Throwable $t) {
            return $this->catchDatabaseThrowable($t);
        }
    }

    /**
     * create coustomer if it necessary
     *
     * @param EDbConnection $connection
     * @param int $id
     * @param array $customer
     *
     * return int
     */
    public function syncCustomerByQboId($connection, $id, &$customer = [])
    {
        $response = $this->executeQueryToBaseQbo('select * from Customer Where Id = \'' . intval($id) . "'");
        $customer = $response['QueryResponse']['Customer'][0] ?? null;
        if (!isset($customer) or !is_array($customer)) {
            return null;
        }

        $params['fields'] = $customer;
        $params['fields']['ApplicationId'] = $this->appId;
        $params['fields']['qboId'] = $params['fields']['Id'];
        $params['fields']['Enabled'] = 1;
        unset($params['fields']['Email']);
        $params['fields']['Email'] = $customer['PrimaryEmailAddr']['Address'] ?? '';
        //unset($params['fields']['Phone']);
        $params['fields']['PhoneQbo'] = $customer['PrimaryPhone']['FreeFormNumber'] ?? '';
        $params['fields']['ParentObject'] = $this->companyObjectId;
        unset($params['fields']['Id']);

        $this->checkAndCreateColumns($connection, $params['fields']);
        $existingObject = $this->checkCustomerExist($connection, $params['fields']);
        //dd($customer,$existingObject,$params['fields']['SyncToken']);
        if (!$existingObject) {
            //dump($params, $existingObject, $this->appId);
            $res = $this->Api8->createObject($connection, $params, 0);

            //dd($res, $res['body']['data'][0]['id']);

            return ($res['body']['data'][0]['id'] ?? '');

        } else {
            if (($existingObject['SyncToken'] ?? '') != $params['fields']['SyncToken']) {

                $params['fields']['Phone'] = $params['fields']['PhoneQbo'];

                //$params['fields']['id'] = $existingObject['id'];
                //$params['fields']['ParentObject'] = $this->companyObjectId;

                //dump($params, $existingObject, $this->appId);
                //$this->Api8->updateObject

                return $this->updateObject($connection, $params['fields'], $existingObject['id'], $this->appId);

                //dd($affected);
                //dd($affected, $affected['body']['data']['id'] ?? '');

                //return ($affected['body']['data']['id'] ?? '');
                //dd($params, $affected);
            } else {
                //dd($existingObject['id']);

                return ($existingObject['id'] ?? '');
            }
        }

    }

    /**
     * add column to object
     *
     * @param EDbConnection $connection
     * @param array $params
     * return void
     */
    private function addObjectColumns($connection, $params)
    {
        $columnName = $params['ColumnName'];
        $dataType = 'text';
        $table = 'objects';
        try {
            $exist = $connection->createCommand(
                'select column_name from information_schema.columns
                        where table_name = \'' . $table . '\' and column_name = :column_name')
                ->queryRow(
                    true, [
                    ':column_name' => $columnName,
                ]);

            if (empty($exist)) {
                $connection->createCommand(
                    'ALTER TABLE ' . $table . ' ADD COLUMN "' . $columnName . '" ' . $dataType . ';')
                    ->execute();

            }

            $insert = $this->genInsertMultipart($params);

            $connection->createCommand(
                'insert into objectcolumns ' . $insert['fields'] . ' ' . $insert['values'])
                ->execute($insert['params']);

        } catch (Throwable $exception) {
            Yii::log($exception->getMessage(), 'error');

        }
    }

    /**
     * Create new columns in objects if it necessary
     *
     * @param EDbConnection $connection
     * @param array $fields
     * return void
     *
     */
    private function checkAndCreateColumns($connection, $fields)
    {


        if (!isset($fields['qboId'])) {
            $fields['qboId'] = '';
        }
        if (!isset($fields['selectedPaidPlan'])) {
            $fields['selectedPaidPlan'] = '';
        }
        if (!isset($fields['waitDayBeforeRemember'])) {
            $fields['waitDayBeforeRemember'] = '';
        }
        if (!isset($fields['weekDayForRemember'])) {
            $fields['weekDayForRemember'] = '';
        }
        if (!isset($fields['New_invocie_text'])) {
            $fields['new_invocie_text'] = ''; //constant('qboNewInvoiceText');
        }
        if (!isset($fields['Remider_text'])) {
            $fields['remider_text'] = ''; //constant('qboReminderText');
        }
        if (!isset($fields['promo_code'])) {
            $fields['promo_code'] = '';
        }
        if (!isset($fields['proadvisor'])) {
            $fields['proadvisor'] = '';
        }
        if (!isset($fields['disabled'])) {
            $fields['disabled'] = '';
        }


        $strInvoicesIdsForSync = implode('\',\'', array_keys($fields));
        $where = '(\'' . $strInvoicesIdsForSync . '\')';

        $sql = 'select string_agg("ColumnName",\'","\') 
        from objectcolumns where "AppId" = ' . $this->appId . ' and "ColumnName" in ' . $where;
        $res = $connection->createCommand($sql)
            ->queryScalar();
        eval("\$existColls = [\"$res\"];");



        foreach ($fields as $customerFieldName => $customerFieldValue) {
            if (!in_array($customerFieldName, $existColls)) {
                //dump($customerFieldName);
                $objectColumn = [
                    'AppId' => $this->appId,
                    'ColumnName' => $customerFieldName,
                    'Visible' => 1,
                    'SortOrder' => 9999,
                    'SystemColumn' => 1,
                    'ColumnType' => 'text',

                ];
                $this->addObjectColumns($connection, $objectColumn);
            }
        }

    }

    /**
     * Check if front opened in mobile device
     *
     * return bool
     *
     */
    public function webClientLikeMobile()
    {
        $mobile_agent_array = [
            'ipad',
            'iphone',
            'android',
            'pocket',
            'palm',
            'windows ce',
            'windowsce',
            'cellphone',
            'opera mobi',
            'ipod',
            'small',
            'sharp',
            'sonyericsson',
            'symbian',
            'opera mini',
            'nokia',
            'htc_',
            'samsung',
            'motorola',
            'smartphone',
            'blackberry',
            'playstation portable',
            'tablet browser',
        ];
        $agent = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
        // var_dump($agent);exit;
        foreach ($mobile_agent_array as $value) {
            if (strpos($agent, $value) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     *
     * return data for logout page
     *
     *
     */
    public function actionLogoutView()
    {
        session_unset();
        //echo 'you have been logout from billto.me service';
        if ($this->webClientLikeMobile()) {
            require_once(constant('pathBill2meTamplateMobileLogout'));
        } else {
            require_once(constant('pathBill2meTamplateLogout'));
        }
    }

    /**
     *
     * print count of unpaid invoices
     *
     * @param EDbConnection $connection
     * return void
     *
     */
    public function printCountUnpaidInvoices($connection)
    {
        if ($this->companyObjectId) {
            $where = '  "ActionName" = :ActionName and "ObjectId" = :ObjectId and "StatusId" <> :Status ';

            $countEeventId = $connection->createCommand()
                ->select('count(id)')
                ->from('backend')
                ->where(
                    $where, [
                    ":ActionName" => 'Invoice',
                    ":ObjectId" => $this->companyObjectId,
                    ":Status" => constant('qboConfigStatusIdClosedInvoice'),
                ])//->getText();
                ->queryScalar();
            print(intval($countEeventId));
        }
    }

    /**
     *
     * print count of total synced invoices
     *
     * @param EDbConnection $connection
     * return void
     *
     */
    public function printCountSyncedInvoices($connection)
    {
        if ($this->companyObjectId) {
            $where = ' "BackendId" in ' .
                ' (select id from backend where "ActionName" = :ActionName and "ObjectId" = :ObjectId) ' .
              ' and "ColumnName" = :ColumnName ' .
//              ' and "BackendId" in ' .
//                ' (select id from backend where "ActionName" = :ActionName2 and "ObjectId" = :ObjectId) ' .
                '  ';

            $countEeventId = $connection->createCommand()
                ->select('count("ColumnValue")')
                ->from('backendext')
                ->where(
                    $where, [
                    ":ActionName" => 'sendInvoice',
//                    ":ActionName2" => 'Invoice',
                    ":ObjectId" => $this->companyObjectId,
                    ":ColumnName" => 'invoiceEventId',
                ])//->getText();
                ->queryScalar();
            print_r($countEeventId);
        }
    }

    /**
     *
     * return data fore view form
     *
     *
     */
    public function actionQboDisconnectView()
    {
        if ($this->webClientLikeMobile()) {
            require_once(constant('pathBill2meTamplateMobileDisconnect'));
        } else {
            require_once(constant('pathBill2meTamplateDisconnect'));
        }

        if (isset($_SESSION['access_token'])) {
        //if (true) {
            $connection = Yii::app()->db;
            $this->qboCallbackOAuth2($connection, null, true, true, true);
            if ($this->companyObjectId) {
                $this->client->revokeToken(
                    $this->configs['revocationEndPointUrl'], $this->access_token, $this->refresh_token);

                $connection->createCommand()
                    ->delete(
                        'oauthtokens', '"ObjectId" = :id', [
                        ':id' => intval($this->companyObjectId),
                    ]);
                $connection->createCommand()
                    ->delete(
                        'oauthtokens', '"ObjectId" = 0 or "ObjectId" is null or "AccessToken" is null');

                $this->disconnectService($connection);





            } else {
                //echo 'sorry, access denied';
            }
        }

        session_unset();
        $_SESSION["actual_link"] = constant('qboHost') . "/entryPoint";

    }
    /**
     *
     * return data fore view form
     *
     *
     */
    public function actionQboEntryPointView()
    {
        //Cache-Control: no-cache Cache-Control: no-store
        $_SESSION["actual_link"] = constant('qboHost')."/entryPoint";
        $connection = Yii::app()->db;
        $this->qboCallbackOAuth2($connection, null, true, true, true);
        header_remove('Location');
        if ($this->firstSync) {
            $this->syncAllInvoicesFromServer(Yii::app()->db);
        }
        if ($this->webClientLikeMobile()) {
            require_once(constant('pathBill2meTamplateMobile'));
        } else {
            require_once(constant('pathBill2meTamplate'));
        }

        exit();
        //        $this->sendResponse(
        //            200, [
        //            $this->companyObjectData,
        //            $this->appId,
        //        ]);
        //$this->sendResponse(200, $this->syncAllInvoicesFromServer(Yii::app()->db));
        //dd($this->syncAllInvoicesFromServer(Yii::app()->db));

    }

    /**
     *
     * return data fore view form start page
     *
     *
     */
    public function actionQboStartPointView()
    {
        if (!isset($_SESSION['access_token'])) {
            if ($this->webClientLikeMobile()) {
                require_once(constant('pathBill2meTamplateMobileStartPoint'));
            } else {
                require_once(constant('pathBill2meTamplateMobileStartPoint'));
            }
        } else {
            session_unset();
            //header("Location: ./entryPoint", true, 302);
            //$_SESSION["actual_link"] = constant('qboHost')."/entryPoint";;
            if (!headers_sent()) {
                exit(header("Location: " . constant('qboHost') . "/entryPoint", true, 302));
            }
            exit();
        }

    }

    /**
     * returns data of company object
     *
     *
     *
     */
    public function actionQboObjectData()
    {
        $this->qboCallbackOAuth2(Yii::app()->db, 0);
        dd($this->companyObjectData);

    }

    /**
     * make and return shortlink by auth qbo company
     *
     *
     *
     */
    public function actionQboShortLink()
    {
        $this->qboCallbackOAuth2(Yii::app()->db);
        require_once(ROOTPATH . '/../app/extensions/qrcode/phpqrcode/qrlib.php');
        //$this->qboCallbackOAuth2(Yii::app()->db, 0);//do not allowed use script without authorisation in QBO
        $host = Yii::app()->params['app_host'];

        $iOSUrl = $host . '/?appid=' . $this->appId . '&screenid=' . $this->newInvoiceScreenId . '&objid=' .
            $this->companyObjectId . '&os=ios';
        $androidUrl = $host . '/?appid=' . $this->appId . '&screenid=' . $this->newInvoiceScreenId . '&objid=' .
            $this->companyObjectId ;
        $contr = Yii::app()
            ->createController('Objects')[0];
        $iOS_url_short = $contr->create_shortlink($iOSUrl);

        if (true) {
            $apiAuthGlc = new Restapi8authController(null);
            $code = $apiAuthGlc->getCode(
                Yii::app()->db, [
                'applicationId' => $this->appId,
                'objectId' => $this->companyObjectId,
            ]);
            if (is_string($code)) {
                $androidUrl = $androidUrl . '&glc=' . $code;
            }
        }

        $android_url_short = $contr->create_shortlink($androidUrl);

        echo $android_url_short;
        exit;
    }

    /**
     * make and return shortlink by auth qbo company
     *
     *
     *
     */
    public function getQboShortLink()
    {
        require_once(ROOTPATH . '/../app/extensions/qrcode/phpqrcode/qrlib.php');
        //$this->qboCallbackOAuth2(Yii::app()->db, 0);//do not allowed use script without authorisation in QBO
        $host = Yii::app()->params['app_host'];

        $iOSUrl = $host . '/?appid=' . $this->appId . '&screenid=' . $this->newInvoiceScreenId . '&objid=' .
            $this->companyObjectId . '&os=ios';
        $androidUrl = $host . '/?appid=' . $this->appId . '&screenid=' . $this->newInvoiceScreenId . '&objid=' .
            $this->companyObjectId ;
        $contr = Yii::app()
            ->createController('Objects')[0];
        $iOS_url_short = $contr->create_shortlink($iOSUrl);

        if (true) {
            $apiAuthGlc = new Restapi8authController(null);
            $code = $apiAuthGlc->getCode(
                Yii::app()->db, [
                'applicationId' => $this->appId,
                'objectId' => $this->companyObjectId,
            ]);
            if (is_string($code)) {
                $androidUrl = $androidUrl . '&glc=' . $code;
            }
        }

        $android_url_short = $contr->create_shortlink($androidUrl);

        return $android_url_short;
    }

    /**
     * make and return qbo image with link of entry point
     *
     *
     *
     */
    public function actionQboQrImg()
    {
        $this->qboCallbackOAuth2(Yii::app()->db);
        require_once(ROOTPATH . '/../app/extensions/qrcode/phpqrcode/qrlib.php');
        //$this->qboCallbackOAuth2(Yii::app()->db, 0);//do not allowed use script without authorisation in QBO
        $host = Yii::app()->params['app_host'];

        $iOSUrl = $host . '/?appid=' . $this->appId . '&screenid=' . $this->newInvoiceScreenId . '&objid=' .
            $this->companyObjectId . '&os=ios';
        $androidUrl = $host . '/?appid=' . $this->appId . '&screenid=' . $this->newInvoiceScreenId . '&objid=' .
            $this->companyObjectId ;
        $contr = Yii::app()
            ->createController('Objects')[0];
        $iOS_url_short = $contr->create_shortlink($iOSUrl);

        if (true) {
            $apiAuthGlc = new Restapi8authController(null);
            $code = $apiAuthGlc->getCode(
                Yii::app()->db, [
                'applicationId' => $this->appId,
                'objectId' => $this->companyObjectId,
            ]);
            if (is_string($code)) {
                $androidUrl = $androidUrl . '&glc=' . $code;
            }
        }

        $android_url_short = $contr->create_shortlink($androidUrl);

        ob_start();
        QRCode::png($android_url_short, null, 'L', 10, false);
        $imageString = base64_encode(ob_get_contents());
        ob_end_clean();

        $name = 'qrcode_android' . md5(mt_rand()) . '.png';
        $type = 'image/png';
        $size = strlen(base64_decode($imageString));
        header('Content-length: ' . $size);
        header('Content-type: ' . $type);
        header('Content-Disposition: attachment; filename=' . $name);
        ob_clean();
        flush();
        echo base64_decode($imageString); // or base64 decode //hex2bin  //base64_decode
        exit;
    }

    /**
     * return html form with qbo image
     *
     *
     *
     */
    public function actionQboQrImgPage()
    {
        require_once(ROOTPATH . '/../app/extensions/qrcode/phpqrcode/qrlib.php');
        $this->qboCallbackOAuth2(Yii::app()->db, 0);
        $host = Yii::app()->params['app_host'];

        $iOSUrl = $host . '/?appid=' . $this->appId . '&screenid=' . $this->newInvoiceScreenId . '&objid=' .
            $this->companyObjectId . '&os=ios';
        $androidUrl = $host . '/?appid=' . $this->appId . '&screenid=' . $this->newInvoiceScreenId . '&objid=' .
            $this->companyObjectId ;
        $contr = Yii::app()
            ->createController('Objects')[0];
        $iOS_url_short = $contr->create_shortlink($iOSUrl);
        $android_url_short = $contr->create_shortlink($androidUrl);

        ob_start();
        QRCode::png($android_url_short, null, 'L', 10, false);
        $imageString = base64_encode(ob_get_contents());
        ob_end_clean();

        echo '<img src=\'data:image/png;base64,' . ($imageString) . '\'>';

    }

    /**
     * return only html frame with qr code
     *
     *
     *
     */
    public function actionQboQr()
    {
        $this->qboCallbackOAuth2(Yii::app()->db, 0);
        $host = Yii::app()->params['app_host'];

        $iOSUrl = $host . '/?appid=' . $this->appId . '&screenid=' . $this->newInvoiceScreenId . '&objid=' .
            $this->companyObjectId . '&os=ios';
        $androidUrl = $host . '/?appid=' . $this->appId . '&screenid=' . $this->newInvoiceScreenId . '&objid=' .
            $this->companyObjectId ;
        $contr = Yii::app()
            ->createController('Objects')[0];
        $iOS_url_short = $contr->create_shortlink($iOSUrl);
        $android_url_short = $contr->create_shortlink($androidUrl);

        $this->renderPartial(
            "application.views.createapp.step3.qrcode_widget", [
            'url_short' => $android_url_short,
            'filename' => 'qrcode_android.png',
            //md5(mt_rand())
        ]);

    }

    /**
     * Special procedure for first registration qbo object
     *
     *
     *
     */
    public function actionQboEntryPoint()
    {
        $this->qboCallbackOAuth2(Yii::app()->db, 0);
        $this->sendResponse(200, $this->syncAllInvoicesFromServer(Yii::app()->db));
        //dd($this->syncAllInvoicesFromServer(Yii::app()->db));

    }

    /**
     * remove some fields and some new fields create
     *
     * @param array $invoice
     *
     * return array
     */
    public function changeInvoiceFields($invoice)
    {
        //dump($invoice);
        $invoice['qboId'] = $invoice['Id'];
        unset($invoice['Id']);

        foreach ($invoice as $key => $fieldValue) {

            switch ($key) {
                case "BillEmail":
                    if (isset($fieldValue['Address'])) {
                        $invoice['BillEmail'] = $fieldValue['Address'];
                    }
                    break;
                case "CustomerMemo":
                    if (isset($fieldValue['value'])) {
                        $invoice['CustomerMemo'] = $fieldValue['value'];
                    }
                    break;
                case "Line":
                    $lines = [];
                    foreach ($fieldValue as $keyLine => $line) {
                        if (isset($line['DetailType']) and in_array(
                                $line['DetailType'], [
                                'SubTotalLineDetail',
                                'DiscountLineDetail',
                            ])
                        ) {
                            $invoice[$line['DetailType']] = json_encode($line);
                        } else {
                            $lines[] = ($line);
                        }
                    }
                    unset($invoice[$key]);
                    $invoice[$key] = json_encode($lines);

                    break;
                //                case "Line":
                //                    foreach ($fieldValue as $line) {
                //                        if (isset($line['Id'])) {
                //                            $invoice['Line_' . intval($line['Id'])] = json_encode($line);
                //                        }
                //                    }
                //                    unset($invoice[$key]);
                //                    break;
                case "CustomerRef":
                    if (isset($fieldValue['value'])) {
                        $invoice['CustomerId'] = $fieldValue['value'];
                    }

                    if (isset($fieldValue['name'])) {
                        $invoice['CustomerName'] = $fieldValue['name'];
                    }

                    unset($invoice[$key]);
                    break;
                case "CurrencyRef":

                    if (isset($fieldValue['name'])) {
                        $invoice['CurrencyRefName'] = $fieldValue['name'];
                    }

                    if (isset($fieldValue['value'])) {
                        $invoice['CurrencyRefValue'] = $fieldValue['value'];
                    }

                    unset($invoice['CurrencyRef']);

                    if (isset($fieldValue['value'])) {
                        $invoice['CurrencyRef'] = $fieldValue['value'];
                    }

                    break;
                default:
                    if (is_array($fieldValue) or is_object($fieldValue)) {
                        unset($invoice[$key]);
                        $invoice[$key] = json_encode($fieldValue);
                    }
            }

        }

        return $invoice;

    }

    /**
     * get new invoices from qbo server and updating previous invoices
     *
     * @param EDbConnection $connection
     *
     * @return array
     */
    public function syncAllInvoicesFromServer($connection)
    {
        $countCreated = 0;
        $countUpdated = 0;

        if(!$this->companyObjectId){
            return [
                'error' => 'company does not created'
            ];
        }

        if (intval($this->companyObjectData['disabled']) == 1) {
            return [
                'error' => 'company is disabled',
            ];
        }

        if ($invoices = $this->qboGetInvoices()) {

            $invoicesIdsForSync = [];

            foreach ($invoices['QueryResponse']['Invoice'] ?? [] as $invoice) {
                // if (floatval($invoice['Balance']) > 0) {
                $invoicesIdsForSync[] = $invoice['Id'];
                //  }
            }

            if (empty($invoicesIdsForSync)) {
                return null;
            }

            $invoicesIdsForSync = array_diff($invoicesIdsForSync, ['']);

            $strInvoicesIdsForSync = implode('\',\'', $invoicesIdsForSync);
            $where = ' "ColumnName"=\'qboId\' and "ColumnValue" in (\'' . $strInvoicesIdsForSync . '\') 
            and "BackendId" in (select id from "backend" where "ObjectId" = ' . $this->companyObjectId . ') ';

            $backendExists = $connection->createCommand()
                ->select('*')
                ->from('backendext')
                ->where($where)
                ->queryAll();

            $alreadyExistInvoices = [];
            $alreadyExistBalance = [];
            $InvoicesSyncToken = [];
            $balanceHistory = [];
            $paymentsHistory = [];

            foreach ($backendExists as $backendExist) {
                //$where = ' "ColumnName"=\'Balance\' and "BackendId" = ' . intval($backendExist['BackendId']);

                //$alreadyExistBalance[$backendExist['ColumnValue']] = $connection->createCommand()
                //    ->select('ColumnValue')
                //    ->from('backendext')
                //    ->where(
                //        $where)
                //    ->queryScalar();

                $alreadyExistInvoices[$backendExist['ColumnValue']] = $backendExist['BackendId'];

                $where = ' "ColumnName"=\'SyncToken\' and "BackendId" = ' . intval($backendExist['BackendId']);

                $InvoicesSyncToken[$backendExist['ColumnValue']] = $connection->createCommand()
                    ->select('ColumnValue')
                    ->from('backendext')
                    ->where($where)
                    ->queryScalar();

                $where = ' "ColumnName"=\'Balance\' and "BackendId" = ' . intval($backendExist['BackendId']);

                $alreadyExistBalance[$backendExist['ColumnValue']] = $connection->createCommand()
                    ->select('ColumnValue')
                    ->from('backendext')
                    ->where($where)
                    ->queryScalar();

                $where = ' "ColumnName"=\'balanceHistory\' and "BackendId" = ' . intval($backendExist['BackendId']);

                $balanceHistory[$backendExist['ColumnValue']] = $connection->createCommand()
                    ->select('ColumnValue')
                    ->from('backendext')
                    ->where($where)
                    ->queryScalar();

                $where = ' "ColumnName"=\'paymentsHistory\' and "BackendId" = ' . intval($backendExist['BackendId']);

                $paymentsHistory[$backendExist['ColumnValue']] = $connection->createCommand()
                    ->select('ColumnValue')
                    ->from('backendext')
                    ->where($where)
                    ->queryScalar();
            }

            $alreadyExistInvoices = array_diff($alreadyExistInvoices, ['']);

            //$strEventsIdsForSync = '(' . implode(',', array_values($alreadyExistInvoices)) . ')';
            //dd($strEventsIdsForSync, $eventIdsForSync);
            //dd($alreadyExistBalance, $InvoicesSyncToken);
            foreach ($invoices['QueryResponse']['Invoice'] ?? [] as $invoice) {
                if (floatval(
                        $invoice['Balance']) > 0 || (isset($InvoicesSyncToken[$invoice['Id']]) and
                        $InvoicesSyncToken[$invoice['Id']] != $invoice['SyncToken'])
                        //this is not critical, but possible here have to be key 'qboId'
                        //because use isset, debug make later
                ) {

                    $invoice = $this->changeInvoiceFields($invoice);
                    $customerInfo = [];
                    $customerId = $this->syncCustomerByQboId($connection, $invoice['CustomerId'], $customerInfo);
                    $invoice['customerObjectId'] = $customerId;
                    $invoice['customerInfo'] = json_encode($customerInfo);
                    $invoice['TotalAmt'] = $invoice['TotalAmt'] ?? 0;
                    $invoice['balanceHistory'] = '';
                    if (isset($balanceHistory[$invoice['qboId']])) {
                        if ($history = json_decode($balanceHistory[$invoice['qboId']])) {
                            $invoice['balanceHistory'] = $history;
                        }
                    }
                    $invoice['paymentsHistory'] = '';
                    if (isset($paymentsHistory[$invoice['qboId']])) {
                        if ($history = json_decode($paymentsHistory[$invoice['qboId']])) {
                            $invoice['paymentsHistory'] = $history;
                        }
                    }
                    if (in_array($invoice['qboId'], array_keys($alreadyExistInvoices))) {
                        //if status <> 'closed'?


                        if ($InvoicesSyncToken[$invoice['qboId']] == $invoice['SyncToken']) {//if SyncToken was changed
                            continue;
                        };

                        //fields for updating already existing Event
                        $newEvent['fields'] = [
                            'ObjectId' => $this->companyObjectId,
                            'ApplicationId' => $this->appId,
                            'ScreenId' => $this->newInvoiceScreenId,
                            'ActionName' => constant('qboEventNameForInvoice'),
                            'StatusId' => $this->newInvoiceStatus,
                            'Value' => json_encode($invoice),
                        ];

                        $eventForUpdateId = intval($alreadyExistInvoices[$invoice['qboId']]);

                        if ((intval($invoice['Balance']) !== intval($alreadyExistBalance[$invoice['qboId']] ?? 0)) and
                            (($invoice['Balance']) !== ($invoice['TotalAmt']))
                        ) {

                            if(!is_array($invoice['balanceHistory'])){
                                $invoice['balanceHistory'] = [];
                            }
                            if(!is_array($invoice['paymentsHistory'])){
                                $invoice['paymentsHistory'] = [];
                            }

                            $invoice['balanceHistory'][] = [
                                'date' => date("Y-m-d H:i:s"),
                                'sum' => number_format($invoice['Balance'], 2),
                            ];
                            $invoice['paymentsHistory'][] = [
                                'date' => date("Y-m-d H:i:s"),
                                'sum' => number_format(
                                    floatval($alreadyExistBalance[$invoice['qboId']]) - floatval($invoice['Balance']),
                                    2),
                            ];

                        }

                        if(is_array($invoice['paymentsHistory'])){
                            $invoice['paymentsHistory'] = json_encode($invoice['paymentsHistory']);
                        }
                        if(is_array($invoice['balanceHistory'])){
                            $invoice['balanceHistory'] = json_encode($invoice['balanceHistory']);
                        }

                        $updateEvent['fields'] = [
                            'id' => $eventForUpdateId,
                            'ApplicationId' => $this->appId,
                            'Value' => json_encode($invoice),
                        ];

                        if (intval($invoice['Balance']) > 0 and ($invoice['Balance'] !== $invoice['TotalAmt'])) {
                            $updateEvent['fields']['StatusId'] = $this->partPaidStatus;
                        }

                        if (intval($invoice['Balance']) == 0) {
                            $updateEvent['fields']['StatusId'] = constant('qboConfigStatusIdClosedInvoice');
                        }



                        $eventData = $this->Api8->updateEventV8($connection, $updateEvent, 0);
                        $EventId = $lastId = $eventData['data'][0]['id'] ?? null;
                        //dd($eventData,$EventId);
                        if (is_null($EventId)) {//if event doesn't exist - get out
                            // return null;
                        } else {
                            $countUpdated++;
                        }
                        //$this->updateEvent();
                    } else {
                        //fields for create new Event
                        $newEvent['fields'] = [
                            'ObjectId' => $this->companyObjectId,
                            'ApplicationId' => $this->appId,
                            'ScreenId' => $this->newInvoiceScreenId,
                            'ActionName' => constant('qboEventNameForInvoice'),
                            'StatusId' => $this->newInvoiceStatus,
                            'Value' => json_encode($invoice),
                        ];

                        if (intval($invoice['Balance']) > 0 and ($invoice['Balance'] !== $invoice['TotalAmt'])) {
                            $newEvent['fields']['StatusId'] = $this->partPaidStatus;
                        }

                        //dd($newEvent);
                        $eventData = ($this->Api8->eventCreate($connection, $newEvent, false));
                        $newEventId = $lastId = $eventData['body']['data'][0]['id'] ?? null;
                        //dd($eventData,$newEventId);
                        if (is_null($newEventId)) {//if event doesn't exist - get out
                            // return null;
                        } else {
                            $countCreated++;

                            if (!$this->firstSync) {
                                //$params = [
                                //    'appid' => $this->appId,
                                //    'objid' => $customerId,
                                //    'screenid' => constant('qboConfigScreenId'),
                                //];
                                //$link = Yii::app()->createAbsoluteUrl('applications/newlink', $params);
                                //$client_portal = Utils::getTenantNameIfConsole();
                                $host = str_replace('-admin.', '.', constant('qboHost'));
                                $appId = $this->appId;
                                $screenId = constant('qboConfigScreenId');
                                $objid = $customerId;

                                $link = $host . '/?appid=' . $appId . '&screenid=' . $screenId . '&objid=' . $objid;

                                $controller = new EController('');
                                $shortLink = $controller->create_shortlink($link);
                                //here were returned simple long link #marker 3 - fix it
                                $authData = [
                                    'object' => ['id' => $this->companyObjectId],
                                ];
                                $body = [
                                    'eventId' => $newEventId,
                                    'goToEventId' => 1,//first message by event? may be does not necessary
                                    'message' => $this->companyObjectData['new_invocie_text'] .
                                        ' Please open your Bill2me app, or this link',
                                ];

                                //dd($body, $authData, $client_portal);
                                //$this->sendMessage($connection, $body, $authData);

                                $this->checkThroughBotsChannel(
                                    $connection, $authData['object'], $objid, ['id' => $newEventId], true);

                            }

                        }
                    }

                }

            }

        };

        return [
            'created' => $countCreated,
            'updated' => $countUpdated,
        ];

    }

    /**
     * get all items
     *
     *
     */

    public function actionGetItems()
    {
        $this->qboCallbackOAuth2(Yii::app()->db);//have to be executed before every call to qbo server
        $this->sendResponse(200, $this->qboGetItems());
        //dd($this->qboGetItems());
    }

    /**
     * allow get all items of current company
     *
     * return array
     */
    public function qboGetItems()
    {

        return $this->executeQueryToBaseQbo('select * from Item');//

    }

    /**
     * get all bundles
     *
     *
     */

    public function actionGetBundles()
    {
        $this->qboCallbackOAuth2(Yii::app()->db);//have to be executed before every call to qbo server
        $this->sendResponse(200, $this->qboGetGroups());
        //dd($this->qboGetGroups());
    }

    /**
     * allow get all bundles(groups of items) of current company
     *
     * return array
     */
    public function qboGetGroups()
    {

        return $this->executeQueryToBaseQbo('select * from Item where Type=\'Group\'');//

    }

    /**
     * get all categories
     *
     *
     */

    public function actionGetCategories()
    {
        $this->qboCallbackOAuth2(Yii::app()->db);//have to be executed before every call to qbo server
        $this->sendResponse(200, $this->qboGetCategories());
        //dd($this->qboGetCategories());
    }

    /**
     * allow get all categories(of items) of current company
     *
     * return array
     */
    public function qboGetCategories()
    {

        return $this->executeQueryToBaseQbo('select * from Item where Type=\'Category\'');//

    }

    /**
     * get all non-closed invoices
     *
     *
     */

    public function actionGetInvoices()
    {
        $this->qboCallbackOAuth2(Yii::app()->db);//have to be executed before every call to qbo server
        $this->sendResponse(200, $this->qboGetInvoices());
        //dd($this->qboGetInvoices());
    }

    /**
     * allow get all non-closed ivoices by api
     *
     * return array
     */
    public function qboGetInvoices()
    {

        return $this->executeQueryToBaseQbo('select * from Invoice');//where Balance<>0 - only actual;

    }

    /**
     * allow get all customers of company by api
     *
     */
    public function actionGetCustomers()
    {
        $this->qboCallbackOAuth2(Yii::app()->db);//have to be executed before every call to qbo server
        $this->sendResponse(200, $this->qboGetCustomers());
        //dd();
    }

    /**
     * allow get all customers of company by api
     *
     * return array
     */
    public function qboGetCustomers()
    {

        return $this->executeQueryToBaseQbo('select * from Customer');

    }

    /**
     * allow making variables queries to qbo server
     *
     * @param string $query
     * return array
     */
    public function executeQueryToBaseQbo($query)
    {
        $encodeQuery = urlencode($query);
        $url = constant(
                'qboUrlApi') . '/company/' . $this->realmId . '/query?query=' . $encodeQuery . '&minorversion=45';

        //dump($url);
        return $this->client->callForApiEndpointGet($url, $this->access_token);
    }

    /**
     * send messages for qbo customer
     *
     * return void
     */
    public function actionSettarif()
    {
        try {

            $connection = Yii::app()->db;
            $this->qboCallbackOAuth2($connection);
            $res = $this->setObjectTarif($connection);

        } catch (Error $error) {
            $res = $this->catchAnyThrowable($error, 400, $error->getMessage(), $error->getMessage(), false);
        }

        $this->sendResponse($res['code'], $res['body']);
    }

    /**
     * send messages for qbo customer
     *
     * @param EDbConnection $connection
     *
     * return void
     */
    public function setObjectTarif($connection)
    {

        if (!isset($_GET['tarif'])) {
            return [
                'code' => 200,
                'body' => 'nothing to create/change',
            ];
        }
        $tableName = 'objects';
        $params = [
            'selectedPaidPlan' => intval($_GET['tarif']),
        ];

        $connection->createCommand()
            ->update(
                $tableName, $params, ' "id"  = ' . intval($this->companyObjectId));

        return [
            'code' => 200,
            'body' => 'tariff plan changed',
        ];
    }

    /**
     * set count of days after off it will be send message with invoice
     *
     * return void
     */
    public function actionSetDayBeforeRemember()
    {
        try {

            $connection = Yii::app()->db;
            $this->qboCallbackOAuth2($connection);
            $res = $this->setObjectWaitDayBeforeRemember($connection);

        } catch (Error $error) {
            $res = $this->catchAnyThrowable($error, 400, $error->getMessage(), $error->getMessage(), false);
        }

        $this->sendResponse($res['code'], $res['body']);
    }

    /**
     * set field waitDayBeforeRemember
     *
     * @param EDbConnection $connection
     *
     * return void
     */
    private function setObjectWaitDayBeforeRemember($connection)
    {

        if (!isset($_GET['daybeforeremember'])) {
            return [
                'code' => 200,
                'body' => 'nothing to create/change',
            ];
        }
        $tableName = 'objects';
        $params = [
            'waitDayBeforeRemember' => intval($_GET['daybeforeremember']),
        ];

        $connection->createCommand()
            ->update(
                $tableName, $params, ' "id"  = ' . $this->companyObjectId);

        return [
            'code' => 200,
            'body' => 'count day changed',
        ];
    }

    /**
     * set text filed "promo code"
     *
     * return void
     */
    public function actionSetPromoText()
    {
        try {

            $connection = Yii::app()->db;
            $this->qboCallbackOAuth2($connection);
            $res = $this->setPromoText($connection);

        } catch (Error $error) {
            $res = $this->catchAnyThrowable($error, 400, $error->getMessage(), $error->getMessage(), false);
        }

        $this->sendResponse($res['code'], $res['body']);
    }

    /**
     *  set New_invocie_text filed
     *
     * @param EDbConnection $connection
     *
     * return void
     */
    private function setPromoText($connection)
    {

        if (!isset($_POST['promotext'])) {
            return [
                'code' => 200,
                'body' => 'nothing to create/change',
            ];
        }
        if(strlen($_POST['promotext'])>8){
            return [
                'code' => 200,
                'body' => 'text to long, it have be less 9 characters',
            ];
        }
        $tableName = 'objects';
        $params = [
            'promo_code' => trim($_POST['promotext']),
        ];

        $connection->createCommand()
            ->update(
                $tableName, $params, ' "id"  = ' . $this->companyObjectId);

        return [
            'code' => 200,
            'body' => 'It can take up to 24 hours to check the code.',
        ];
    }
    /**
     * set text for new invoice
     *
     * return void
     */
    public function actionSetInvoiceText()
    {
        try {

            $connection = Yii::app()->db;
            $this->qboCallbackOAuth2($connection);
            $res = $this->setInvoiceText($connection);

        } catch (Error $error) {
            $res = $this->catchAnyThrowable($error, 400, $error->getMessage(), $error->getMessage(), false);
        }

        $this->sendResponse($res['code'], $res['body']);
    }

    /**
     *  set New_invocie_text filed
     *
     * @param EDbConnection $connection
     *
     * return void
     */
    private function setInvoiceText($connection)
    {
        if (!isset($_POST['invoicetext'])) {
            return [
                'code' => 200,
                'body' => 'nothing to create/change',
            ];
        }
        if(strlen($_POST['invoicetext'])>80){
            return [
                'code' => 200,
                'body' => 'text to long, it have be less 80 characters',
            ];
        }
        $tableName = 'objects';
        $params = [
            'new_invocie_text' => addslashes($_POST['invoicetext']),
        ];

        $connection->createCommand()
            ->update(
                $tableName, $params, ' "id"  = ' . $this->companyObjectId);

        return [
            'code' => 200,
            'body' => 'new_invocie_text changed',
        ];
    }

    /**
     * set text for new reminde invoice
     *
     * return void
     */
    public function actionSetRemiderText()
    {
        try {

            $connection = Yii::app()->db;
            $this->qboCallbackOAuth2($connection);
            $res = $this->setRemiderText($connection);

        } catch (Error $error) {
            $res = $this->catchAnyThrowable($error, 400, $error->getMessage(), $error->getMessage(), false);
        }

        $this->sendResponse($res['code'], $res['body']);
    }

    /**
     *  set Remider_text filed
     *
     * @param EDbConnection $connection
     *
     * return void
     */
    private function setRemiderText($connection)
    {
        if (!isset($_POST['remidertext'])) {
            return [
                'code' => 200,
                'body' => 'nothing to create/change',
            ];
        }
        if(strlen($_POST['remidertext'])>80){
            return [
                'code' => 200,
                'body' => 'text to long, it have be less 80 characters',
            ];
        }
        $tableName = 'objects';
        $params = [
            'remider_text' => addslashes($_POST['remidertext']),
        ];

        $connection->createCommand()
            ->update(
                $tableName, $params, ' "id"  = ' . $this->companyObjectId);

        return [
            'code' => 200,
            'body' => 'remider_text changed',
        ];
    }

    /**
     * set week day for remember operation by object
     *
     * return void
     */
    public function actionSetWeekDayForRemember()
    {
        try {

            $connection = Yii::app()->db;
            $this->qboCallbackOAuth2($connection);
            $res = $this->setObjectDayForRemember($connection);

        } catch (Error $error) {
            $res = $this->catchAnyThrowable($error, 400, $error->getMessage(), $error->getMessage(), false);
        }

        $this->sendResponse($res['code'], $res['body']);
    }

    /**
     *  set week day for cron operation remember
     *
     * @param EDbConnection $connection
     *
     * return void
     */
    private function setObjectDayForRemember($connection)
    {

        if (!isset($_GET['weekdayforremember'])) {
            return [
                'code' => 200,
                'body' => 'nothing to create/change',
            ];
        }
        $tableName = 'objects';
        $params = [
            'weekDayForRemember' => intval($_GET['weekdayforremember']),
        ];

        $connection->createCommand()
            ->update(
                $tableName, $params, ' "id"  = ' . $this->companyObjectId);

        return [
            'code' => 200,
            'body' => 'week day changed',
        ];
    }

    /**
     * send messages for qbo customer
     *
     * return void
     */
    public function actionSendMessage()
    {
        $data = $this->checkAuth();

        $connection = Yii::app()->db;
        try {
            $body = Request::rawParams('application/json');

            $connection = Yii::app()->db;
            $res = $this->sendMessage($connection, $body, $data);

        } catch (Error $error) {
            $res = $this->catchAnyThrowable($error, 400, $error->getMessage(), $error->getMessage(), false);
        }

        $this->sendResponse($res['code'], $res['body']);
    }

    /**
     * get owner of event
     *
     * @param EDbConnection $connection
     * @param array $backendData
     *
     * @return array
     */
    private function getQboCompanyForEvent($connection, $backendData)
    {
        return $connection->createCommand(
            'select * from objects o where o.id = :id')
            ->queryRow(true, [':id' => $backendData['ObjectId']]);
    }

    /**
     * get object - consumer of event
     *
     * @param EDbConnection $connection
     * @param array $backendData
     *
     * @return array
     */
    private function getQboCustomerForEvent($connection, $backendData)
    {
        $nameOfAttributs = [
            'customerObjectId',
        ];
        $customerObjectId = $this->findAttributValue($backendData, $nameOfAttributs);

        return $connection->createCommand(
            'select * from objects o where o.id = :id')
            ->queryRow(true, [':id' => $customerObjectId]);
    }

    /**
     * try to extract pnone numbers from customer data
     *
     * @param array $objectData
     *
     * @return array
     */
    private function extractPhonesFromCustomer($objectData)
    {
        $res = [];

        $res[] = $objectData['PhoneQbo'] ?? '';

        if (isset($objectData['Mobile'])) {
            if ($arr = json_decode($objectData['Mobile'], true)) {
                $res[] = $arr['FreeFormNumber'] ?? '';
            }
        }

//        $res[] = $objectData['PhoneQbo'];

        return $res;
    }

    /**
     * try to find some data in backend records
     *
     * @param array $backendData
     * @param array $searchVal
     *
     *
     * @return array
     */
    public function findAttributValue($backendData, $searchVal)
    {
        $res = [];

        if (isset($backendData['Value'])) {
            foreach ($backendData['Value'] as $key => $value) {
                if (isset($value['ColumnName']) && isset($value['ColumnValue'])) {
                    if (in_array(($value['ColumnName']), $searchVal)) {
                        return $value['ColumnValue'];
                    }
                }
            }
        }

        //dd($backendData,$res);
        return $res;
    }

    /**
     * get sms/mms shluz from list
     *
     * @param EDbConnection $connection
     * @param array $phones
     * @param string $type
     * @param array $smsMail
     * @param array $mmsMail
     *
     *
     *
     * @return array
     */
    private function getShluzData247($connection, $phones, $type = 'sms', $smsMail = [], $mmsMail = [])
    {

        if (constant('qboDevMode')) {
            return false;
        }

        $makeRequestToValidationService = [
            0 => false,
            //do not make request
            1 => true,
            //make request
        ];
        foreach ($makeRequestToValidationService as $makeRequest) {
            foreach ($phones as $phone) {
                if ($phone = $this->checkPhoneNumber($phone)) {
                    $res = $this->getSmsShluzByNumber($connection, $phone, $makeRequest);
                    if (isset($res['mms_address']) and $res['mms_address']) {
                        $mmsMail[] = $res['mms_address'];
                        if ($type == 'mms') {
                            return [
                                'phone' => $phone,
                                'shluz' => $res['mms_address'],
                            ];
                        }
                    }
                    if (isset($res['sms_address']) and $res['sms_address']) {
                        $smsMail[] = $res['sms_address'];

                        if ($type == 'sms') {
                            return [
                                'phone' => $phone,
                                'shluz' => $res['sms_address'],
                            ];
                        }
                    }

                }
            }
        }

        $smsMail = array_diff($smsMail, ['']);
        $mmsMail = array_diff($mmsMail, ['']);

        return false;
    }

    /**
     * get sms/mms shluz from list
     *
     * @param EDbConnection $connection
     * @param string $phone
     *
     *
     *
     * @return array
     */
    private function searshInList($connection, $phone)
    {
        //dd($this->Data247ListName, $phone, $this->columnNameForPhone);
        return $connection->createCommand(
            'select * from "' . $this->Data247ListName . '" where "' . $this->columnNameForPhone .
            '" = :phone order by date, "DateCreate" ')
            ->queryRow(true, [':phone' => $phone]);
    }

    /**
     * get sms/mms shluz from service data247
     *
     * @param EDbConnection $connection
     * @param string $phone
     *
     *
     *
     * @return array
     */
    private function makeRequestToData247($connection, $phone)
    {
//        dd('try to call data247 api!!!'); //#marker 2 komment it
        $ch = curl_init();

        curl_setopt_array(
            $ch, [
            CURLOPT_URL => constant('data274Url') . $phone,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        ]);

        $res = curl_exec($ch);
        $resultInfo = curl_getinfo($ch);
        $err = curl_error($ch);

        if ($resultInfo['http_code'] == 200 && $res != false) {
            $res = json_decode($res);
            Yii::log($resultInfo, \CLogger::LEVEL_TRACE);
            Yii::log('data from service data247', \CLogger::LEVEL_TRACE);
        } else {
            $res = json_decode([]);
            Yii::log('get data from service data247 fail on curl', \CLogger::LEVEL_ERROR);
            Yii::log($err, \CLogger::LEVEL_ERROR);
            //Yii::log($res, \CLogger::LEVEL_ERROR);
            //Yii::log($resultInfo, \CLogger::LEVEL_ERROR);
            //Yii::log(constant('dataDogApiKey'), \CLogger::LEVEL_ERROR);
            //Yii::log(constant('dataDogApplicationKey'), \CLogger::LEVEL_ERROR);
            //Yii::log(constant('dataDogUrlForGettingLog'), \CLogger::LEVEL_ERROR);
        }

        return $res;
    }

    /**
     * get sms/mms shluz for phone number
     *
     * @param EDbConnection $connection
     * @param string $phone
     * @param bool $makeRequestToValidationService
     *
     *
     * @return array
     */
    private function getSmsShluzByNumber($connection, $phone, $makeRequestToValidationService = false)
    {
        $data = $this->searshInList($connection, $phone);
        if (!$data and $makeRequestToValidationService) {
            $data = $this->makeRequestToData247($connection, $phone);

            //test data
            //            $phone = '+14155559933';
            //            $data = (object)[
            //
            //                "response" => $data = (object)[
            //
            //                    "status" => "OK",
            //                    "results" => [
            //                        (object)[
            //                            "phone" => "14155559933",
            //                            "wless" => "n",
            //                            "carrier_name" => "MULTIPLE OCN LISTING",
            //                            "carrier_id" => 67765,
            //                            "sms_address" => "",
            //                            "mms_address" => "",
            //                        ],
            //                    ],
            //                ],
            //
            //            ];

            //            $phone = '+18574008011';
            //            $data = (object)[
            //
            //                "response" => $data = (object)[
            //
            //                    "status" => "OK",
            //                    "results" => [
            //                        (object)[
            //                            "phone" => "18574008011",
            //                            "wless" => "n",
            //                            "carrier_name" => "Bandwidth.com",
            //                            "carrier_id" => 50025,
            //                            "sms_address" => "",
            //                            "mms_address" => "",
            //                        ],
            //                    ],
            //                ],
            //
            //            ];

            //            $phone = '+1234567890';
            //            $data = (object)[
            //
            //                "response" => $data = (object)[
            //
            //                    "status" => "OK",
            //                    "results" => [
            //                        (object)[
            //                            "type" => "Landline",
            //                            "firstname" => "fnameee",
            //                            "lastname" => "lnameee",
            //                            "address" => "streeeet address",
            //                            "city" => "cityyyy",
            //                            "state" => "TX",
            //                            "zip" => "1234",
            //                            "carrier_name" => "AT+T Local",
            //                            "carrier_id" => "28287",
            //                            "sms_address" => "sms@test.com",
            //                            "mms_address" => "mms@test.com",
            //                        ],
            //                    ],
            //                ],
            //            ];

            //            $phone = '+19736419059';
            //            $data = (object)[
            //
            //                "response" => $data = (object)[
            //
            //                    "status" => "OK",
            //                    "results" => [
            //                        (object)[
            //                            "phone" => "19736419059",
            //                            "wless" => "y",
            //                            "carrier_name" => "Bellsouth Mobility, LLC - GA",
            //                            "carrier_id" => 6,
            //                            "sms_address" => "9736419059@wireless.bellsouth.com",
            //                            "mms_address" => "9736419059@wireless.bellsouth.com",
            //                        ],
            //                    ],
            //                ],
            //
            //            ];
            //dd($data);

            if (!empty($data)) {
                if (isset($data->response->results) && is_array($data->response->results)) {
                    foreach ($data->response->results as $shluzData) {
                        $forJson = [
                            'phone' => $phone,
                            'date' => 'now()',
                            'status' => $shluzData->status ?? '',
                            "firstname" => $shluzData->firstname ?? '',
                            "lastname" => $shluzData->lastname ?? '',
                            "address" => $shluzData->address ?? '',
                            "city" => $shluzData->city ?? '',
                            "state" => $shluzData->state ?? '',
                            "zip" => $shluzData->zip ?? '',
                            'wless' => $shluzData->wless ?? '',
                            'carrier_name' => $shluzData->carrier_name ?? '',
                            'carrier_id' => $shluzData->carrier_id ?? '',
                            'sms_address' => $shluzData->sms_address ?? '',
                            'mms_address' => $shluzData->mms_address ?? '',
                        ];
                        $params['fields'] = [
                            'listId' => $this->listId,
                            'tableName' => constant('qboListForData247'),
                            'json' => json_encode($forJson),
                            'name' => constant('qboListForData247'),
                            'applicationId' => $this->appId,
                        ];
                        $api8List = new Restapi8listController(null);
                        $result = $api8List->add($connection, 0, $params);

                        //dd($result);
                    }
                }
            }
            $data = $this->searshInList($connection, $phone);

        }

        return $data;
    }

    /**
     * validate phone number: this is mobile phone and thi is american phone
     *
     * @param string $phone
     *
     *
     * @return array
     */
    private function checkPhoneNumber($phone)
    {
        $str = preg_replace("/[^0-9]/", '', $phone);

        $a = mb_substr($str, 0, 1);
        if (strlen($str) < 10 or strlen($str) > 11) {
            return false;
        }
        if (strlen($str) == 11 and $a !== '1') {
            return false;
        }
        if (strlen($str) == 10) {
            $str = '1' . $str;
        }
        $str = '+' . $str;

        return $str;
    }

    /**
     * create new bill from Vendor
     *
     * @param array $vendor
     * @param array $item
     * @param string $mountlyFee
     * @param string $monthStart
     * @param string $monthEnd
     * @param array $accDate
     * return array
     */
    private function billCreate($vendor, $item, $mountlyFee, $monthStart, $monthEnd, $accDate)
    {

        //        return $this->executeQueryToBaseQbo('select * from bill');
        $monthStart = explode(' ', $monthStart)[0];
        $monthEnd = explode(' ', $monthEnd)[0];
        $url = constant('qboUrlApi') . '/company/' . $this->realmId . '/bill';//?minorversion=45
        $data = json_encode(
            [
                'VendorRef' => ['value' => $vendor['Id']],
                'Line' => [
                    [
                        'DetailType' => 'AccountBasedExpenseLineDetail',
                        'Description' => $item['Name'] . " for $monthStart - $monthEnd ",
                        ////SalesItemLineDetail //AccountBasedExpenseLineDetail
                        'Amount' => $mountlyFee,
                        'ItemBasedExpenseLineDetail' => [
                            'ItemRef' => [
                                'name' => $item['Name'],
                                'value' => $item['Id'],
                            ],
                        ],
                        'AccountBasedExpenseLineDetail' => [
                            'AccountRef' => [
                                'name' => $accDate['Name'],
                                'value' => $accDate['Id'],
                            ],
                        ],
                    ],
                ],
                //                'CurrencyRef' => [],
            ]);

        return $this->client->callForApiEndpointPost($url, $this->access_token, strval($data), true);
    }

    /**
     * create new bankAcc
     *
     * @param array $vendor
     * return array
     */
    private function bankAccCreate($vendor)
    {
        $url = constant('qboUrlApiPayments') . '/vendor/' . $vendor['Id'] . '/bank-accounts';//?minorversion=45

        //        return $this->client->callForApiEndpointGet($url, $this->access_token);

        $data = json_encode(
            [
                //                'phone' => constant('qboBankAccPhone'),
                'routingNumber' => constant('qboBankRoutingNumber'),
                'name' => constant('qboBankName'),
                'accountType' => constant('qboBankAccountType'),
                'accountNumber' => constant('qboBankAccountNumber'),
            ]);

        return $this->client->callForApiEndpointPost($url, $this->access_token, strval($data), true);

    }

    /**
     * create new vendor
     *
     *
     * return array
     */
    private function vendorCreate()
    {
        $url = constant('qboUrlApi') . '/company/' . $this->realmId . '/vendor';//?minorversion=45
        $data = json_encode(
            [
                'DisplayName' => constant('qboVendorName'),
                'Title' => constant('qboVendorName'),
                'CompanyName' => constant('qboCompanyName'),
            ]);

        return $this->client->callForApiEndpointPost($url, $this->access_token, strval($data), true);
    }

    /**
     * create new item
     *
     * @param string $itemName
     * @param array $accDate
     * return array
     */
    private function itemCreate($itemName, $accDate)
    {
        $url = constant('qboUrlApi') . '/company/' . $this->realmId . '/item';//?minorversion=45
        $data = json_encode(
            [
                //                'Type' => 'Service',
                'Name' => $itemName,
                'ExpenseAccountRef' => [
                    'name' => $accDate['Name'],
                    'value' => $accDate['Id'],
                ]
                //                "TrackQtyOnHand" => true,
                //                "Name" => "Garden Supplies",
                //                "QtyOnHand" => 10,
                //                "IncomeAccountRef" => [
                //                    "name" => "Sales of Product Income",
                //                    "value" => "79",
                //                ],
                //                "AssetAccountRef" => [
                //                    "name" => "Inventory Asset",
                //                    "value" => "81",
                //                ],
                //                "InvStartDate" => "2015-01-01",
                //                "Type" => "Inventory",
                //                "ExpenseAccountRef" => [
                //                    "name" => "Cost of Goods Sold",
                //                    "value" => "80",
                //                ],

            ]);

        return $this->client->callForApiEndpointPost($url, $this->access_token, strval($data), true);
    }

    /**
     * check vendor is exist
     *
     * return array
     */
    private function vendorExist()
    {
        return $this->executeQueryToBaseQbo(
            'select * from vendor where DisplayName = \'' . constant('qboVendorName') . '\'');
    }

    /**
     *
     * try to find acc by name
     *
     * @param string $accName
     * return array
     */
    private function getAccount($accName)
    {
        return $this->executeQueryToBaseQbo(
            'select * from Account where Name = \'' . $accName . '\'');
    }

    /**
     * check item is exist
     *
     * @param string $itemName
     * return array
     */
    private function itemExist($itemName)
    {
        return $this->executeQueryToBaseQbo(
            'select * from item where Name = \'' . $itemName . '\'');
    }

    /**
     * create new bill on QBO server
     *
     * @param $monthStart
     * @param $monthEnd
     * @param $currentPlan
     * @param $mountlyFee
     * @param $countOfInvoices
     * return array
     */
    private function createQboBill($monthStart, $monthEnd, $currentPlan, $mountlyFee, $countOfInvoices)
    {
        $resVendor = $this->vendorExist();

        if (!isset($resVendor['QueryResponse']) || !is_array($resVendor['QueryResponse'])) {
            print "Incorrect format response vendorExist";

            return false;
        }
        if (empty($resVendor['QueryResponse'])) {

            $resVendor = $this->vendorCreate();

            $vendor = $resVendor['Vendor'];

            $resBankAcc = $this->bankAccCreate($vendor);
            //dd($resBankAcc);
            if (!is_array($resBankAcc)) {
                print "Could not create a bank account for vendor";

                return false;
            }

        } else {
            if (!isset($resVendor['QueryResponse']['Vendor'][0]) ||
                !is_array($resVendor['QueryResponse']['Vendor'][0])
            ) {

                return false;
            }
            $vendor = $resVendor['QueryResponse']['Vendor'][0];

        }
        if (!is_array($vendor)) {
            print "Could not create a vendor";

            return false;
        }
        $nameItem = "Bill2me mobile invoicing"; //$monthStart - $monthEnd
        $resItem = $this->itemExist($nameItem);
        if (!isset($resItem['QueryResponse']) || !is_array($resItem['QueryResponse'])) {
            print "incorrect format response itemExist";

            return false;
        }

        //        $accName = 'Cost of Goods Sold';
        $accName = 'Miscellaneous';
        $resAcc = $this->getAccount($accName);
        if (!isset($resAcc['QueryResponse']['Account'][0]) || !is_array($resAcc['QueryResponse']['Account'][0])) {
            //            print "Could not found 'Cost of Goods Sold' account";
            print "Could not found 'Miscellaneous' account";

            return false;
        }
        $acc = $resAcc['QueryResponse']['Account'][0];

        if (!isset($resItem['QueryResponse']['Item'][0]) || !is_array($resItem['QueryResponse']['Item'][0])) {
            $resItem = $this->itemCreate($nameItem, $acc);
            //            dd(1, $resItem);
            $item = $resItem['Item'];
        } else {
            if (!isset($resItem['QueryResponse']['Item'][0]) || !is_array($resItem['QueryResponse']['Item'][0])) {
                return false;
            }
            $item = $resItem['QueryResponse']['Item'][0];

        }

        if (!isset($resItem['QueryResponse']['Item'][0]) || !is_array($resItem['QueryResponse']['Item'][0])) {
            return false;
        }
        //        dd(2, $resItem);
        //        dd(2, $this->billCreate($vendor, $item, $mountlyFee, $monthStart, $monthEnd, $acc));

        return $this->billCreate($vendor, $item, $mountlyFee, $monthStart, $monthEnd, $acc);

    }

    /**
     * get all objects with creatd evenls bill for last month
     *
     * @param EDbConnection $connection
     * @param string $from
     * return array
     */
    private function getObjectsWithBill($connection, $from)
    {
        $where = ' "CreateDate" >= \'' . $from . '\' and "ActionName" = \'' . constant('qboEventNameForBill') . '\' ';

        return $connection->createCommand()
            ->select('ObjectId')
            ->from('backend')
            ->where($where)
            ->queryAll();

    }

    /**
     * create bill
     *
     * @param EDbConnection $connection
     * @param array $objects
     * return array
     */
    private function createBillsEvents($connection, $objects)
    {

        foreach ($objects as $object) {
            $dateTimeNow = (new DateTime());
            $iteration = 0;
            $dateCreateObject = new DateTime($object['DateCreate']);
            do {
                $monthStart = $dateCreateObject->format("Y-m-d") . ' 00:00:00';
                Utils::shiftMonthsDate(1, $dateCreateObject, "Y-m-d", true);
                $monthEnd = $dateCreateObject->format("Y-m-d") . ' 00:00:00';

                if ($iteration++ > 0) {
                    if (!($bills = $this->billExist($connection, $object['id'], $monthStart, $monthEnd))) {
                        //                    dd(2, $bills);
                        $this->createBillEvent($connection, $object['id'], $monthStart, $monthEnd);
                    }
                };
                //                dd(1, $bills);

            } while ($dateCreateObject < $dateTimeNow);
            session_unset();
        }
    }

    /**
     * check bill exist
     *
     * @param EDbConnection $connection
     * @param array $objectId
     * @param string $monthStart
     * @param string $monthEnd
     * return array
     */
    private function billExist($connection, $objectId, $monthStart, $monthEnd)
    {

        $where = ' ("ColumnName"=\'monthEnd\' and "ColumnValue" = (\'' . $monthEnd . '\'))  
            and "BackendId" in (select id from "backend" where "ObjectId" = ' . $objectId . ' and "ActionName" = \'' .
            constant('qboEventNameForBill') . '\') ';

        $res = $connection->createCommand()
            ->select('*')
            ->from('backendext')
            ->where($where)
            ->queryAll();

        //dd($res);

        return $res;
    }

    /**
     * create bills
     *
     * @param EDbConnection $connection
     * @param array $objectId
     * @param string $monthStart
     * @param string $monthEnd
     * return array
     */
    private function createBillEvent($connection, $objectId, $monthStart, $monthEnd)
    {

        //        if (!$objectsId) {
        //            return [
        //                'code' => 200,
        //                'body' => 'nothing to create/change',
        //            ];
        //        }

        //$countCreated = 0;
        //        foreach ($objectsId as $objectId) {
        $invoiceData = [
            'countInvoices' => 0,
            'monthStart' => $monthStart,
            'monthEnd' => $monthEnd,
        ];

        $newEvent['fields'] = [
            'ObjectId' => $objectId,
            'ApplicationId' => $this->appId,
            'ScreenId' => $this->newInvoiceScreenId,
            'ActionName' => constant('qboEventNameForBill'),
            'StatusId' => constant('qboConfigStatusIdOpenInvoice'),
            'Value' => json_encode($invoiceData),
        ];

        $eventData = ($this->Api8->eventCreate($connection, $newEvent, false));
        $newEventId = $lastId = $eventData['body']['data'][0]['id'] ?? null;
        #marker 5 unkomment it
        if (is_null($newEventId)) {
            return null;
        }

        //        dump($eventData);
        return $eventData;

        //check this is exist
        //$this->qboCallbackOAuth2($connection, $objectId);
        //
        //dd($res);
        //$countCreated++;

        //        }

        //        return [
        //            'code' => 200,
        //            'body' => 'Created new bills, count: ' . $countCreated,
        //        ];
    }

    /**
     * fill event of bill and create bill on qbo server
     *
     * @param EDbConnection $connection
     * @param array $eventBill
     * @param string $monthEnd
     * return array
     */
    private function fillAndCloseEventBill($connection, $eventBill, $monthEnd)
    {
        //dd($eventBill);
        $objectId = $eventBill['ObjectId'];
        //        $this->qboCallbackOAuth2($connection, $objectId);

        $objectData = $connection->createCommand()//check existing this company in hole database
        ->select('id, DateCreate, selectedPaidPlan')
            ->from('objects')
            ->where('"id" = ' . $objectId)
            ->queryRow();
        //dd($objectData);
        //$dateTimeNow = (new DateTime());
        $dateCreateObject = new DateTime($objectData['DateCreate']);
        $createDateEvent = new DateTime($monthEnd);
        $iteration = 0;

        do {
            $monthStart = $dateCreateObject->format("Y-m-d") . ' 00:00:00';
            Utils::shiftMonthsDate(1, $dateCreateObject, "Y-m-d", true);
            $monthEnd = $dateCreateObject->format("Y-m-d") . ' 00:00:00';
            $iteration++;
        } while ($dateCreateObject < $createDateEvent);

        // for test
        //        Utils::shiftMonthsDate(-10, $dateCreateObject, "Y-m-d", true);
        //        $monthStart = $dateCreateObject->format("Y-m-d") . ' 00:00:00';
        //        Utils::shiftMonthsDate(20, $dateCreateObject, "Y-m-d", true);
        //        $monthEnd = $dateCreateObject->format("Y-m-d") . ' 00:00:00';

        $where =
            ' "CreateDate" >= \'' . $monthStart . '\' and "CreateDate" < \'' . $monthEnd . '\' and "ActionName" = \'' .
            constant('qboEventNameForSendInvoice') . '\' ';
        $resCount = $connection->createCommand()
            ->select('count(id)')
            ->from('backend')
            ->where($where)
            ->queryScalar();

        $freeInvoices = constant('qboFreeCountOfInvoices');

        $currentPlan = constant('qboPlan1');
        $mountlyFee = 9;
        $invoiceFee = 0.2;

        if (intval($objectData['selectedPaidPlan']) == 2) {
            $currentPlan = constant('qboPlan2');
            $mountlyFee = 49;
            $invoiceFee = 0.1;
        }
        $totalCost = $mountlyFee;
        if (intval($resCount) > $freeInvoices) {
            $totalCost += ($invoiceFee * ($resCount - $freeInvoices));
        }

        $invoiceVal = [
            'totalCost' => $totalCost,
            'currentPlan' => $currentPlan,
            'countOfInvoices' => $resCount,
            //            'monthStart' => $monthStart,
            //            'monthEnd' => $monthEnd,
        ];

        $updateEvent['fields'] = [
            'id' => $eventBill['id'],
            'ApplicationId' => $eventBill['ApplicationId'],
            'Value' => json_encode($invoiceVal),
        ];

        $eventData = $this->Api8->updateEventV8($connection, $updateEvent, 0);
        $EventId = $lastId = $eventData['data'][0]['id'] ?? null;

        //dump(
        //    $totalCost, $currentPlan, $resCount, $monthStart, $monthEnd, $eventBill, $objectData['selectedPaidPlan'],
        //    $eventData);

        $this->qboCallbackOAuth2($connection, $objectId);

        if (!$this->companyObjectId) {
            return false;
        }

        return $this->createQboBill($monthStart, $monthEnd, $currentPlan, $mountlyFee, $resCount);

    }

    /**
     * fill events of bill
     *
     * @param EDbConnection $connection
     * @param array $invoicesId
     * return array
     */
    private function fillClosedBill($connection, $invoicesId)
    {
        $invForCloseStr = ' (' . implode(',', $invoicesId) . ') ';

        $arrayEventsBills = $connection->createCommand()
            ->select('*')
            ->from('backend')
            ->where(' "id" in ' . $invForCloseStr)
            ->queryAll();
        //        dd($arrayEventsBills);

        foreach ($arrayEventsBills as $eventBill) {
            $monthEnd = $connection->createCommand()
                ->select('ColumnValue')
                ->from('backendext')
                ->where(' "ColumnName" = \'monthEnd\' and "BackendId" = ' . $eventBill['id'])
                ->queryScalar();

            $res = $this->fillAndCloseEventBill($connection, $eventBill, $monthEnd);
            dump($res);
            unset($_SESSION['refresh_token'],$_SESSION['access_token']);
        }

    }

    /**
     * close event bill
     *
     * @param EDbConnection $connection
     *
     * return array
     */
    private function closeBills($connection)
    {

        $bills = $this->getBillsForClose($connection);
        $invoicesForClose = array_column($bills, 'id');
        //dd($bills);
        if (empty($invoicesForClose)) {
            return false;
        }

        $params = [
            'StatusId' => constant('qboConfigStatusIdClosedInvoice'),
        ];
        $tableName = 'backend';

        $invForCloseStr = ' (' . implode(',', $invoicesForClose) . ') ';

        //#marker 4 unkomment it
        $connection->createCommand()
            ->update(
                $tableName, $params, ' "id" in ' . $invForCloseStr);

        $this->fillClosedBill($connection, $invoicesForClose);

    }

    /**
     * get all opened  createent-bills was created in previous periods
     *
     * @param EDbConnection $connection
     * return array
     */
    private function getBillsForClose($connection)
    {
        $dateTimeNow = (new DateTime());
        $monthEnd = $dateTimeNow->format("Y-m-d") . ' 00:00:00';
        $includeCondition =
            ' id in (select "BackendId" from "backendext" where "ColumnName" = \'monthEnd\' and "ColumnValue"<= \'' .
            $monthEnd . '\') ';

        $where = ' "StatusId" = ' . constant('qboConfigStatusIdOpenInvoice') . ' and "ActionName" = \'' .
            constant('qboEventNameForBill') . '\' and ' . $includeCondition;

        //        dd($where);
        return $connection->createCommand()
            ->select('id')
            ->from('backend')
            ->where($where)
            ->queryAll();
    }

    /**
     * regular procedure for sync data beetwen servers
     *
     * @param EDbConnection $connection
     * return array
     */
    public function syncOpeations($connection)
    {
        $objects = $this->getObjectsForSync($connection);
        //get all objects without ANY bills since last month

        if ($objects) {
            $objectsId = array_column($objects, 'id');
        } else {
            return [
                'code' => 200,
                'body' => 'nothing to create/change',
            ];
        }

        if (!is_array($objectsId)) {
            return [
                'code' => 200,
                'body' => 'nothing to create/change',
            ];
        }
        foreach ($objectsId as $objectId) {
            $this->qboCallbackOAuth2($connection, $objectId);
            //$this->qboCallbackOAuth2($connection, $objectId, true, true, true);
            $res = $this->syncAllInvoicesFromServer($connection);
            dump($res);
            unset($_SESSION['refresh_token'],$_SESSION['access_token']);
        }
    }

    /**
     * get undelivered messages from senderpool and repeat it to gde247 shluz
     *
     * @param EDbConnection $connection
     * @param array $company
     *
     * return int
     */
    private function checkAndRepeatFromSenderPool($connection, $company)
    {

        $where = ' "SendType" = :SendType and "Status" <> :Status ' .
            'and "RecipientType" = :RecipientType and "ApplicationId" = :ApplicationId ' .
            'and "DateCreate" >= DATE(NOW()-INTERVAL \'1 DAY\') ';

        $messages = $connection->createCommand()
            ->select('*')
            ->from('senderpool')
            ->where(
                $where, [
                ":SendType" => 'Push',
                ":Status" => 'success',
                ":RecipientType" => 'Object',
                ":ApplicationId" => intval($this->appId),
            ])
            ->queryAll();

        $objectsCustomerIds = array_column($messages, 'RecipientId');
        $objectsCustomerIds = array_unique($objectsCustomerIds);
        //dd($objectsCustomerIds, 2341);

        foreach ($objectsCustomerIds as $objectCustomerId) {

            $where = ' "BackendId" in ' .
                ' (select id from backend where "ActionName" = :ActionName and "ObjectId" = :ObjectId) ' .
                ' and "ColumnName" = :ColumnName and "ColumnValue" = :ColumnValue';

            $eventId = $connection->createCommand()
                ->select('BackendId')
                ->from('backendext')
                ->where($where, [
                    ":ActionName" => 'Invoice',
                    ":ObjectId" => $company['id'],
                    ":ColumnName" => 'customerObjectId',
                    ":ColumnValue" => $objectCustomerId,
                ])//->getText();
                ->queryScalar();
            //dd($eventId,$objectCustomerId,$company['id']);
            $authData['object']['id'] = $company['id'];
            $body = [
                'eventId' => $eventId,
                'goToEventId' => 1,
                'message' => $company['remider_text'] . ' Please open your Bill2me app, or this link',
            ];
            $this->sendMessage($connection, $body, $authData);
        }

    }

    /**
     *
     *
     * @param EDbConnection $connection
     * @param array $company
     * @param int $dayBefore
     * return int
     */
    private function checkAndRepeatLastIvoices($connection, $company, $dayBefore)
    {
        $includeCondition = ' id in (select "BackendId" from "backendext" 
                    where "ColumnName" = \'Balance\' and "ColumnValue" <> \'0\') ';

        $where = ' "ObjectId" = ' . intval($company['id']) . ' and "StatusId" = ' .
            constant('qboConfigStatusIdSendedInvoice') . ' and "ActionName" = \'' .
            constant('qboEventNameForInvoice') . '\'' .
            ' and ("CreateDate" <= (current_timestamp - interval \'' . intval($dayBefore) . ' day\')) ' .
            ' and ' . $includeCondition;

        $sended = 0;

        $invoiceEvents = $connection->createCommand()
            ->select('id')
            ->from('backend')
            ->where($where)
            ->queryAll();

        $sendedCustomerIds = [];
        foreach ($invoiceEvents as $invoiceEvent) {



            //checking time since las notification
            //' "ColumnName" in (\'\',\'monthEnd\',\'monthEnd\',\'monthEnd\',\'monthEnd\') '
            $includeCondition = ' id in (select "BackendId" from "backendext" where "ColumnName" = ' .
                '\'invoiceEventId\' and "ColumnValue" = \'' . $invoiceEvent['id'] . '\') ';

            $where = ' ("CreateDate" > (current_timestamp - interval \'23 hours\')) ' .
                ' and "ObjectId" = ' . intval($company['id']) . ' and "ActionName" = \'' .
                constant('qboEventNameForSendInvoice') . '\' and ' . $includeCondition;

            $eventExist = $connection->createCommand()
                ->select('*')
                ->from('backend')
                ->where($where)//->getText();
                ->queryAll();

            //if last 23 hours was without notifications
            if (empty($eventExist)) {

                $where = ' "ColumnName" = \'customerObjectId\' and "BackendId" = ' . $invoiceEvent['id'];

                $invoiceCustomerObjectId = $connection->createCommand()
                    ->select('ColumnValue')
                    ->from('backendext')
                    ->where($where)//->getText();
                    ->queryScalar();

                //dd($invoiceEvents, $invoiceCustomerObjectId);


                //only 1 time for 1 customer
                if(in_array($invoiceCustomerObjectId,$sendedCustomerIds)){
                    continue;
                }

                $res = $this->checkThroughBotsChannel($connection, $company, $invoiceCustomerObjectId, $invoiceEvent);
                if ($res) {
                    $sended++;
                    $sendedCustomerIds[] = $invoiceCustomerObjectId;
                }
            }
        }

        return $sended;

    }

    /**
     * regular procedure for sending messages to customers
     *
     * @param EDbConnection $connection
     * @param array $company
     * @param array $customerObjectId
     * @param array $event
     * @param bool $first_message
     *
     * return bool
     */
    public function checkThroughBotsChannel($connection, $company, $customerObjectId, $event, $first_message = false)
    {

        $where = ' "id" = ' . intval($customerObjectId);

        $object = $connection->createCommand()
            ->select('*')//ViberChannel,VkChannel,FacebookChannel,TelegramChannel
            ->from('objects')
            ->where($where)
            ->queryRow();

        //dd($object['id']);//object of customer company

        $viber = $object['ViberChannel'] ?? null;
        $vk = $object['VkChannel'] ?? null;
        $facebook = $object['FacebookChannel'] ?? null;
        $telegram = $object['TelegramChannel'] ?? null;

        $message = $object['remider_text'] . ' Please open your Bill2me app, or this link';

        if ($first_message) {
            $message = $object['new_invocie_text'] . ' Please open your Bill2me app, or this link';
        }

        if ($viber || $vk || $facebook || $telegram) {

            $res = $this->sendToSenderpool($connection, $object, $message, $event['id']);

            //dd("$viber || $vk || $facebook || $telegram", $company);
        } else {

            $authData['object']['id'] = $company['id'];
            $body = [
                'eventId' => $event['id'],
                'goToEventId' => 1,
                'message' => $message,
            ];
            $res = $this->sendMessage($connection, $body, $authData);

        }

        $code = $res['code'] ?? null;

        //dump('object id ' . $company['id'], "result code = " . $code, $res);

        return (intval($code) == 200);

    }

    /**
     * using standart procedure to notification
     *
     * @param EDbConnection $connection
     * @param array $objectCustomer
     * @param string $message
     * @param int $backendId
     * return array
     */
    public function sendToSenderpool($connection, $objectCustomer, $message, $backendId)
    {
        $objectId = $objectCustomer['id'];
        $host = Yii::app()->params['app_host'] ?? null;
        if (!$host || $host == 'http://') {
            $host = str_replace('-admin.', '.', constant('qboHost'));
        }
        //dd($host);


        $where = ' "id" = ' . intval($backendId);

        $backendData = $connection->createCommand()
            ->select('*')
            ->from('backend')
            ->where($where)//->getText();
            ->queryRow();

        $androidUrl = $host . '/?appid=' . $this->appId . '&screenid=' . $this->newInvoiceScreenId . '&objid=' .
            $objectId; //. '&os=android';

        $controller = new EController('');
        $android_url_short = $controller->create_shortlink($androidUrl);

        $message = $message . ' ' . $android_url_short;
        $body = [
            'message' => $message,
            'ApplicationId' => $this->appId,
            //'invite' => 1,
            'objectId' => $objectId,
        ];
        $res = $this->Api8->sendmessagepriority($connection, $body, 0);

        $resCode = $res['code'] ?? null;

        if (intval($resCode) == 200) { // create event with name 'sendInvoice'

            $invoiceData = [
                'phone' => '-',
                'channel' => 'Push',
                'invoiceEventId' => $backendData['id'] ?? null,
                //'invoiceQboId' => $backendData['qboId'],
                'customerObjectId' => $objectCustomer['id'] ?? null,
                'customerQboId' => $objectCustomer['qboId'] ?? null,
                'customerCompanyName' => $objectCustomer['CompanyName'] ?? null,
                'responseFromService' => $responseFromService ?? '',
                'externalMessageId' => $externalMessageId ?? '',
            ];

            $newEvent['fields'] = [
                'ObjectId' => $backendData['ObjectId'] ?? null,
                'ApplicationId' => $backendData['ApplicationId'] ?? null,
                'ScreenId' => $backendData['ScreenId'] ?? null,
                'ActionName' => constant('qboEventNameForSendInvoice'),
                'StatusId' => $backendData['StatusId'] ?? null,
                'Value' => json_encode($invoiceData),
            ];

            $eventData = ($this->Api8->eventCreate($connection, $newEvent, false));
            //dd($eventData);
            $newOrderId = $lastId = $eventData['body']['data'][0]['id'] ?? null;
            if (is_null($newOrderId)) {//if event doesn't exist - get out
                return $res ?? null;
            }

        }

        return $res;

    }

    /**
     * regular procedure for sending messages to customers
     *
     * @param EDbConnection $connection
     * return int
     */
    public function notificationForCustomers($connection)
    {
        $companies = $this->getObjectsForSync($connection);
        $repeated = 0;
        $repeatedToSms = 0;
        foreach ($companies as $company) {
            $dayBefore = $company['waitDayBeforeRemember'] ?? null;
            $dayOfWeek = $company['weekDayForRemember'] ?? null;
            $id = $company['id'] ?? null;

            if (!$dayBefore or !$dayOfWeek or !$id) {
                continue;
            }

            $dayBefore = intval($dayBefore);
            $dayOfWeek = intval($dayOfWeek);

            $this->companyObjectId = $id;

            $repeatedToSms += $this->checkAndRepeatFromSenderPool($connection, $company);
            //dd($repeatedToSms);

            if ($dayOfWeek !== intval(date("N"))) {
                continue;
            }

            $repeated += $this->checkAndRepeatLastIvoices($connection, $company, $dayBefore);

            //dump("objectid = $id", $dayBefore, $dayOfWeek);
        }

        return $repeated;
    }

    /**
     * regular procedure for operations with bills
     *
     * @param EDbConnection $connection
     * return array
     */
    public function billOpeations($connection)
    {

        $objects = $this->getObjectsForCreateInvoices($connection);
        //get all objects without ANY bills since last month

        if ($objects) {
            $this->createBillsEvents($connection, $objects);
        }
        $this->closeBills($connection);
        //        if ($objects) {
        //            $objectsId = array_column($objects, 'id');
        //        } else {
        //            return [
        //                'code' => 200,
        //                'body' => 'nothing to create/change',
        //            ];
        //        }
        //
        //        if (!is_array($objectsId)) {
        //            return [
        //                'code' => 200,
        //                'body' => 'nothing to create/change',
        //            ];
        //        }
        //
        //        foreach ($objects as $object) {
        //
        //        }

        //get all opened bills for objects without ANY bills since last month
        //dd(0,$invoices);
        //        if ($bills) {
        //
        //
        //
        //
        //        }

        //        if (is_array($objectsId)) {
        //            foreach ($objectsId as $objectId) {
        //                $this->someoper($connection, $objectsId);
        //            }
        //        }
        print('success');
    }

    /**
     * get all objects with type 'Company'
     *
     * @param EDbConnection $connection
     * return array
     */
    private function getObjectsForSync($connection)
    {

        $where = ' not ("LegalName" is null) and not ("LegalName" = \'\') ';// need except MobileTrade service Objects

        return $connection->createCommand()//check existing this company in hole database
        ->select('*')
            ->from('objects')
            ->where($where)
            ->queryAll();

    }

    /**
     * get all objects for creating new payment plan
     *
     * @param EDbConnection $connection
     * return array
     */
    private function getObjectsForCreateInvoices($connection)
    {

        $dateModified = new DateTime();//new DateTime('2004-03-31'); - visokosniy
        $strDate = Utils::shiftMonthsDate(-3, $dateModified, "Y-m-d", true);
        $dayFromBegin = $strDate . " 00:00:00";
        //$dayFromEnd = $strDate . " 23:59:59";

        $excludedObjects = $this->getObjectsWithBill($connection, $dayFromBegin);
        //with OPENED and CLOSED bills created since last month
        $excludeCondition = '';
        if ($excludedObjects) {
            $excluded = array_column($excludedObjects, 'ObjectId');
            $excludeCondition = ' and id not in (' . implode(',', $excluded) . ') ';
        }
        //        $where = ' "DateCreate" between \'' . $strDateFrom . '\' and \'' . $strDateTo .
        //            '\' and id in (select "ObjectId" from appobjects where "ApplicationId" = ' . $this->appId . ')';

        //exclude from this procedure all objects with OPENED and CLOSED bills created since last month
        $where = ' "DateCreate" < \'' . $dayFromBegin .
            '\' and id in (select "ObjectId" from appobjects where "ApplicationId" = ' . $this->appId . ')' .
            $excludeCondition . ' and not ("LegalName" is null) and not ("LegalName" = \'\') ';  // need except MobileTrade service Objects

        //dd($where);
        return $connection->createCommand()//check existing this company in hole database
        ->select('*')
            ->from('objects')
            ->where($where)
            ->queryAll();

    }

    /**
     * send messages for qbo customer
     *
     * @param EDbConnection $connection
     * @param array $body
     * @param array $authData
     * return array
     */
    private function sendMessage($connection, $body, $authData)
    {

        $fields = $body;

        $constraint = new Assert\Collection(
            [
                'eventId' => [
                    new Assert\NotNull(
                        [
                            'message' => 'No EventId param',
                        ]),
                    new ApiEventId(),
                ],
                'message' => [
                    new Assert\Optional(
                        [
                            new Assert\Length(
                                [
                                    'min' => 1,
                                    'max' => 255,
                                ]),
                            new Assert\Regex(
                                [
                                    'pattern' => '/^[a-zA-Zа-яА-Я0-9_\.\,\s]+$/msui',
                                    'match' => true,
                                    'message' => ' Supported symbols - ' .
                                        '"A-Z", "a-z", "А-я", "а-я", "0-9", "_", ".", "," and whitespaces. ',
                                ]),
                        ]),
                ],

                'goToEventId' => [
                    new Assert\Optional(
                        [
                            new Assert\Choice([
                                'choices' => ['0', '1', 0, 1]
                            ])
                        ]),
                ],

            ]);

        $errors = $this->globalValidator($fields, $constraint);
        if (is_array($errors)) {

            return $errors;
        }

        try {

            $params['fields']['id'] = $fields['eventId'];
            $params['fields']['values'] = 1;

            $eventData = $this->Api8->viewEvent($connection, 0, $params);
            //
            $backendData = $eventData['body']['data'][0]['attributes'] ?? null;
            $backendDataId = $eventData['body']['data'][0]['id'] ?? null;
            if (is_null($backendData)) {//if event doesn't exist - get out
                return [
                    'code' => '500',
                    'body' => 'event does not exist',
                ];
            }
            //dd($eventData['body']['data'][0]['attributes']['ObjectId'] ,$eventData);

            if (isset($authData['object']['id']) and $authData['object']['id'] > 0) {
                //dd($authData['user']->id);
                if (($backendData['ObjectId'] ?? 0) !== $authData['object']['id']) {
                    return [
                        'code' => '500',
                        'body' => 'this does not yours event ',
                    ];
                }
            }

            $objectCustomer = $this->getQboCustomerForEvent($connection, $backendData);
            $objectCompany = $this->getQboCompanyForEvent($connection, $backendData);

            $phones = $this->extractPhonesFromCustomer($objectCustomer);

            if (empty($phones)) {
                return [
                    'code' => '500',
                    'body' => 'no phones was found for this customer',
                ];
            }

            $create = $this->checkAndCreateListIfItNecessary($connection);

            if ($create === false) {
                return [
                    'code' => '500',
                    'body' => 'system error - does not exist list with phones validations',
                ];
            }

            //dd($phones, $backendData['ObjectId'], $objectCustomer);
//            $phones = ['19736419059'];//#marker 1 komment it
            $channel = 'sms';
            $smsShluz = $this->getShluzData247($connection, $phones, $channel);

            if (!$smsShluz) {
                return [
                    'code' => '500',
                    'body' => ' cant found valid sms way for this customer',
                ];
            }

            //dd($smsShluz, $eventData);

            //auth buy shortlink

            //cron sync data from qbo
            //cron - create bill
            //
            $accautnConf = constant('DEFAULT_MESSAGE_ACCOUNTS');
            $mailgunConf = $accautnConf['modules']['mailgun'];
            $mailgun = new Mailgun\Mailgun($mailgunConf['key']);

            //            $queryString = [
            //                'pretty' => 'yes',
            //                'message-id' => '20200204113209.1.3B434C340A2EAAC8@mx.mobsted.com',
            //            ];
            //
            //            return [
            //                'code' => 200,
            //                'body' => ($mailgun->get($mailgunConf['domain'] . '/events', $queryString)),
            //            ];

            $host = Yii::app()->params['app_host'] ?? null;
            if (!$host || $host == 'http://') {
                $host = str_replace('-admin.', '.', constant('qboHost'));
            }
            //dd($host);

            $addEventId = $fields['goToEventId'] ?? null;
            $targetScreen = $this->newInvoiceScreenId;
            if (intval($addEventId)) {
                $targetScreen = constant('qboConfigInvoiceScreenId');
            }

            $androidUrl = $host . '/?appid=' . $this->appId . '&screenid=' . $targetScreen . '&objid=' .
                $objectCustomer['id']; //. '&os=android';

            if (intval($addEventId)) {
                $androidUrl = $androidUrl . "&eventId=" . $fields['eventId'];
            }

            if (true) {
                $apiAuthGlc = new Restapi8authController(null);
                $code = $apiAuthGlc->getCode(
                    $connection, [
                    'applicationId' => $this->appId,
                    'objectId' => $objectCustomer['id'],
                ]);
                $androidUrl = $androidUrl . '&glc=' . $code;
            }

            $controller = new EController('');
            $android_url_short = $controller->create_shortlink($androidUrl, null, true);

            $message = $fields['message'] ?? false;
            if (!$message) {
                //$message = 'Dr. client<br>' . "\n\n<br>\nPlease, check your invoice.\n\n<br><br>Tap link " . $android_url_short;
                $message = 'Please open your Bill2me app, or this link';
            }
            $message = ' ' . $message . ' ' . $android_url_short;

            //dd($message);
            $result = $mailgun->sendMessage(
                'mx.mobsted.com', [
                'from' => ' ' . $objectCompany['CompanyName'] . '<' . 'noreply@mobsted.com' . '>',//'noreply@billto.me'
                // $objectCompany['Email']
                'to' => $smsShluz['shluz'],
                //'iklun@ya.ru'
                'subject' => ' Your Invoice',
                //. $backendDataId,
                'html' => $message,
            ]);

            if (isset($result) && $result->http_response_code == 200) {
                sleep(constant('qboDelayMessageVerifyMailGun'));
                $externalMessageId = str_replace('<', '', $result->http_response_body->id);
                $externalMessageId = str_replace('>', '', $externalMessageId);
                $queryString = [
                    'pretty' => 'yes',
                    'message-id' => $externalMessageId,
                ];

                $response = $mailgun->get($mailgunConf['domain'] . '/events', $queryString);
            } else {
                return [
                    'code' => '500',
                    'body' => 'system error - sms/mms could not be sended',
                ];
            }

            $responseFromService = '';
            if (isset($response->http_response_body->items) && is_array($response->http_response_body->items)) {
                //$responseFromService = json_encode(array_pop($response->http_response_body->items));
                //dd($response->http_response_body->items);
                //more than 1000 symbols
            }

            $invoiceData = [
                'phone' => $smsShluz['phone'],
                'channel' => $channel,
                'invoiceEventId' => $backendDataId,
                //'invoiceQboId' => $backendData['qboId'],
                'customerObjectId' => $objectCustomer['id'],
                'customerQboId' => $objectCustomer['qboId'],
                'customerCompanyName' => $objectCustomer['CompanyName'],
                'responseFromService' => $responseFromService ?? '',
                'externalMessageId' => $externalMessageId ?? '',
            ];

            $newEvent['fields'] = [
                'ObjectId' => $backendData['ObjectId'],
                'ApplicationId' => $backendData['ApplicationId'],
                'ScreenId' => $backendData['ScreenId'],
                'ActionName' => constant('qboEventNameForSendInvoice'),
                'StatusId' => $backendData['StatusId'],
                'Value' => json_encode($invoiceData),
            ];

            $eventData = ($this->Api8->eventCreate($connection, $newEvent, false));

            $newOrderId = $lastId = $eventData['body']['data'][0]['id'] ?? null;
            if (is_null($newOrderId)) {//if event doesn't exist - get out
                return null;
            }

            $params = [
                'StatusId' => constant('qboConfigStatusIdSendedInvoice'),
            ];
            $tableName = 'backend';

            $affected = $connection->createCommand()
                ->update(
                    $tableName, $params, '"id" = :id', [
                    ':id' => $backendDataId,
                ]);

            //dd($result, $response);

            return $eventData;

        } catch (Throwable $exception) {
            Yii::log($exception->getMessage(), CLogger::LEVEL_ERROR);

            return $this->catchDatabaseThrowable($exception);
        }

    }

    /**
     * create store data list fo responses from Data247 service
     *
     * @param EDbConnection $connectionItem
     * @param string $name
     * @param int $appId
     * return bool
     */
    private function listExist($connectionItem, $name, $appId)
    {
        try {
            $list = $connectionItem->createCommand(
                'select * from list where name = :name and "AppId" = :appId')
                ->queryRow(
                    true, [
                    ':name' => $name,
                    ':appId' => $appId,
                ]);
            if (!empty($list)) {
                $this->listId = ($list['id']);
                $this->Data247ListName = 'list' . $this->listId . '_' . $this->listNameForData247;

                return true;
            }
        } catch (Throwable $exception) {
            Yii::log($exception->getMessage(), 'error');

        }

        return false;
    }

    /**
     * create store data list fo responses from Data247 service
     *
     * @param EDbConnection $connection
     * return array
     */
    private function checkAndCreateListIfItNecessary($connection)
    {
        $listName = $this->listNameForData247;

        $listExist = $this->listExist($connection, $listName, $this->appId);

        if (!$listExist) {
            $structure = $this->getStructureListForData247($listName);

            $api8List = new Restapi8listController(null);
            $params['fields'] = [
                'description' => 'for response from Data247',
                'structure' => $structure,
                'name' => $listName,
                'AppId' => $this->appId,
            ];
            $created = $api8List->createList($connection, 0, $params);

            if (!isset($created['body']['data'][0]['id'])) {
                return false;
            }
            $this->listId = ($created['body']['data'][0]['id']);
            $this->Data247ListName = 'list' . $this->listId . '_' . $this->listNameForData247;

            return $created;
        }

        return [
            'code' => '200',
            'body' => ['already exist' => $listExist],
        ];
    }

    /**
     * make correct format of data for auto response in case of some db error
     *
     * @param Throwable $throwable
     *
     * @return array
     */
    public function catchDatabaseThrowable(Throwable $throwable) : array
    {
        return $this->catchAnyThrowable(
            $throwable, 500, 'Database error' . $throwable->getMessage() . $throwable->getTraceAsString(),
            'Some error throwed by Database, please contact with your system administrator if this happens again');
    }

    /**
     * get data247 structure
     *
     * @return string $listNameForData247
     * return string
     */

    private function getStructureListForData247($listNameForData247)
    {
        return '{
        "version": 1.0,
        "links": [],
        "tables": [
            {
                "name": "' . $listNameForData247 . '",
                "fields": [
                    {
                        "name": "phone",
                        "type": "varchar(20)"
                    },
                    {
                        "name": "date",
                        "type": "timestamp without time zone"
                    }, 
                    {
                        "name": "type",
                        "type": "varchar(255)"
                    },
                    {
                        "name": "firstname",
                        "type": "varchar(255)"
                    },
                    {
                        "name": "lastname",
                        "type": "varchar(255)"
                    },
                    {
                        "name": "address",
                        "type": "varchar(255)"
                    },
                    {
                        "name": "city",
                        "type": "varchar(255)"
                    },
                    {
                        "name": "state",
                        "type": "varchar(255)"
                    },
                    {
                        "name": "zip",
                        "type": "varchar(255)"
                    },
                    {
                        "name": "status",
                        "type": "varchar(255)"
                    },
                    {
                        "name": "wless",
                        "type": "varchar(255)"
                    },
                    {
                        "name": "carrier_name",
                        "type": "varchar(255)"
                    },
                    {
                        "name": "carrier_id",
                        "type": "varchar(255)"
                    }, 
                    {
                        "name": "sms_address",
                        "type": "varchar(255)"
                    },
                    {
                        "name": "mms_address",
                        "type": "varchar(255)"
                    }
                ]
            }
           
        ]
    }';
    }

    /**
     * extract data of object
     *
     * @return EDbConnection $connection
     * @return string $realmId
     * return array
     */

    private function getObjectByRealmId($connection, $realmId)
    {
        $params = [
            'realmId' => $realmId,
        ];

        return $alreadyExistAuth = $connection->createCommand()
            ->select('*')
            ->from('oauthtokens')
            ->where(
                '"Params"->>\'realmId\' = :realmId and not("AccessToken" is null) ', $params)
            ->queryRow();
    }

    /**
     * delete invoice by qboId field
     *
     * @return EDbConnection $connection
     * @return int $id
     * return array
     */

    private function deleteInvoiceByQboId($connection, $id)
    {
        $params = [
            'id' => $id,
            'ColumnName' => 'qboId',
            'companyObjectId' => $this->companyObjectId,
        ];

        $where = ' "ColumnName" = :ColumnName and "ColumnValue" = :id 
            and "BackendId" in (select id from "backend" where "ObjectId" = :companyObjectId) ';

        $companyObjectId = $connection->createCommand()
            ->select('BackendId')
            ->from('backendext')
            ->where($where, $params)
            ->queryScalar();

        Yii::app()->db->createCommand()
            ->delete(
                'backendext', '"BackendId" = :id', [
                ':id' => intval($companyObjectId),
            ]);

        $affected = Yii::app()->db->createCommand()
            ->delete(
                'backend', '"id" = :id', [
                ':id' => intval($companyObjectId),
            ]);

//        dd($backendExists, $affected);

        return ['deleted' => $affected];
    }

    /**
     * proicesing and get off event by requeest from qbo server
     *
     * @return EDbConnection $connection
     * @return string $entityName
     * @return string $id
     * @return string $operation
     * return void
     */

    private function processEventNotifications($connection, $entityName, $id, $operation)
    {
        //start here
        //dd($entityName, $id, $operation, 99);
        if (!in_array($operation, ['Update', 'Create', 'Delete'])) {
            return;
        }
        switch ($entityName) {
            case "Customer":
                //$this->syncCustomerByQboId($connection, $id);
                break;
            case "Vendor":
                //$this->syncVendorByQboId();
                break;
            case "Invoice":
                if (in_array($operation, ['Update', 'Create'])) {
                    $this->qboCallbackOAuth2($connection, $this->companyObjectId);
                    $this->sendResponse(200, $this->syncAllInvoicesFromServer(Yii::app()->db));
                }
                if (in_array($operation, ['Delete'])) {
                    $this->sendResponse(200, $this->deleteInvoiceByQboId(Yii::app()->db, $id));
                }

                break;
            case "Bill":
                //$this->syncBillByQboId();
                break;
//            case "Item":
//                $Qbo = new Restapi8qboMobileTradeController();
//                $Qbo->companyObjectId = $this->companyObjectId;
//                if (in_array($operation, ['Update', 'Create'])) {
//                    $Qbo->qboCallbackOAuth2($connection, $Qbo->companyObjectId);
//                    $this->sendResponse(200, $Qbo->syncAllItems(Yii::app()->db));
//                }
//                if (in_array($operation, ['Delete'])) {
//                    $this->sendResponse(200, $Qbo->deleteItemById(Yii::app()->db, $id));
//                }
//                break;
        }
    }

    /**
     * parsing data from qbo service
     *
     * @return EDbConnection $connection
     * @return string $data
     * return void
     */

    private function processData($connection, $data)
    {
        $dataJson = json_decode($data, true);
        if (!isset($dataJson['eventNotifications'][0]['realmId'])) {
            $this->sendResponse(200, '');
        };
        $this->realmId = $dataJson['eventNotifications'][0]['realmId'];
        $objectData = $this->getObjectByRealmId($connection, $this->realmId);
        if (!$objectData) {
            $this->sendResponse(200, '');
        }
        $this->companyObjectId = ($objectData['ObjectId']);
        if (!isset($dataJson['eventNotifications'][0]['dataChangeEvent']['entities']) or
            !is_array($dataJson['eventNotifications'][0]['dataChangeEvent']['entities'])
        ) {
            $this->sendResponse(200, '');
        }
        $entities = $dataJson['eventNotifications'][0]['dataChangeEvent']['entities'];
        foreach ($entities as $entity) {
            list($name, $id, $operation) = array_values($entity);

            $this->processEventNotifications($connection, $name, $id, $operation);
        }
        $this->sendResponse(200, '');
        //dd(1);
        //return ($objectData);
    }

    /**
     * processing incoming data from qbo service
     *
     * @return EDbConnection $connection
     * @return string $data
     * return void
     */

    public function processWebhook($connection, $data)
    {
        session_unset();
        $this->processData($connection, $data);

    }
}


