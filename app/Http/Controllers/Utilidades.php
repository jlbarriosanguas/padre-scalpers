<?php

/*   ___  _   ___  ___ ___    _   _ _____ ___ _    ___ ___   _   ___  ___ ___
 *  | _ \/_\ |   \| _ \ __|  | | | |_   _|_ _| |  |_ _|   \ /_\ |   \| __/ __|
 *  |  _/ _ \| |) |   / _|   | |_| | | |  | || |__ | || |) / _ \| |) | _|\__ \
 *  |_|/_/ \_\___/|_|_\___|   \___/  |_| |___|____|___|___/_/ \_\___/|___|___/  Scalpers
 */

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Facades\Log;
use SoapClient;
use SimpleXMLElement;

class Utilidades extends Controller
{
	/*   ___ _  _  ___  ___ ___ _____   __
	 *  / __| || |/ _ \| _ \_ _| __\ \ / /
	 *  \__ \ __ | (_) |  _/| || _| \ V /
	 *  |___/_||_|\___/|_| |___|_|   |_|
     *
	 *  shopifyStoreSel();
	 *  shopifyVerifyWebhook();
	 *  shopifyGraphQL();
	 *  shopifyRESTAPI();
	 */

	/* @emelero - 16.11.2019 */
	public static function shopifyStoreSel($sfy_store_code)
	{
		// Una pequeña ayuda para identificar propiedades de tiendas. Ampliable.
		switch($sfy_store_code)
		{
			case 'ES':
			case 'FR':
			case 'PT':
			case 'EU':
			case 'UK':
			case 'WW':
			case 'MX':
			case 'DE':
			case 'RO':
			case 'CL':
			case 'CO':
			case 'BE':
			case 'T1':
            case 'TESTENV':
			case 'EMTEST':
				$sfy_store = array("url" => env("SFY_".$sfy_store_code."_STORE_URL"),
					"domain" => env("SFY_".$sfy_store_code."_STORE_DOMAIN"),
					"key" => env("SFY_".$sfy_store_code."_API_KEY"),
					"pwd" => env("SFY_".$sfy_store_code."_API_PWD"),
					"klyPublicKey" => env("KLY_".$sfy_store_code."_PUBLIC_API_KEY"));
				break;
			default:
				$error = [ "errors" => "Invalid store code" ];
				exit(json_encode($error, true));
		}

		// Output
		return $sfy_store;
	}

	public static function storeSelector($storeCode)
    {
        if (in_array($storeCode, explode(',', env('SFY_ENABLED_STORES')))) {
            $storeArray = [
                "url" => env("SFY_" . $storeCode . "_STORE_URL"),
                "domain" => env("SFY_" . $storeCode . "_STORE_DOMAIN"),
                "key" => env("SFY_" . $storeCode . "_API_KEY"),
                "pwd" => env("SFY_" . $storeCode . "_API_PWD"),
                "klyPublicKey" => env("KLY_" . $storeCode . "_PUBLIC_API_KEY"),
            ];
            return $storeArray;
        } else {
            $error = [ "errors" => "Invalid store code" ];
			exit(json_encode($error, true));
        }
    }

	public static function shopifyVerifyWebhook($request)
	{
		// Almacenamos la key cifrada y la tienda de origen almacenadas en el Header
		$hmac_header = $request->header('X-Shopify-Hmac-Sha256');
		$store_origin = $request->header('X-Shopify-Shop-Domain');

		// Establecemos la key correspondiente a la tienda reconocida
		if ($store_origin === env("SFY_T1_STORE_URL"))
		{
			$key = env("SFY_T1_WEBHOOK_TOKEN");
		}
		else if ($store_origin === env("SFY_ES_STORE_URL"))
		{
			$key = env("SFY_ES_WEBHOOK_TOKEN");
		}
		else if ($store_origin === env("SFY_EU_STORE_URL"))
		{
			$key = env("SFY_EU_WEBHOOK_TOKEN");
		}
		else if ($store_origin === env("SFY_UK_STORE_URL"))
		{
			$key = env("SFY_UK_WEBHOOK_TOKEN");
		} else if ($store_origin === env("SFY_WW_STORE_URL"))
		{
			$key = env("SFY_WW_WEBHOOK_TOKEN");
		}
		else if ($store_origin === env("SFY_FR_STORE_URL"))
		{
			$key = env("SFY_FR_WEBHOOK_TOKEN");
		}
		else if ($store_origin === env("SFY_PT_STORE_URL"))
		{
			$key = env("SFY_PT_WEBHOOK_TOKEN");
		}
		else if ($store_origin === env("SFY_MX_STORE_URL"))
		{
			$key = env("SFY_MX_WEBHOOK_TOKEN");
		}
		else if ($store_origin === env("SFY_DE_STORE_URL"))
		{
			$key = env("SFY_DE_WEBHOOK_TOKEN");
		}
		else if ($store_origin === env("SFY_CO_STORE_URL"))
		{
			$key = env("SFY_CO_WEBHOOK_TOKEN");
		}
		else if ($store_origin === env("SFY_CL_STORE_URL"))
		{
			$key = env("SFY_CL_WEBHOOK_TOKEN");
		}
		else if ($store_origin === env("SFY_CO_STORE_URL"))
		{
			$key = env("SFY_CO_WEBHOOK_TOKEN");
		}
		else if ($store_origin === env("SFY_BE_STORE_URL"))
		{
			$key = env("SFY_BE_WEBHOOK_TOKEN");
		}
		else
		{
			$error = [ "errors" => "Invalid store request" ];
			exit(json_encode($error, true));
		}

		// Calculamos el HMAC de la request. Si coincide con la key contenida en el header, la devolvemos
		$calculated_hmac = base64_encode(hash_hmac('sha256', file_get_contents('php://input'), $key, true));

		// Output. Boolean
		return ($hmac_header == $calculated_hmac);
	}

	/* @emelero - 16.11.2019 */
	public static function shopifyGraphQL($shopifyStoreSel, $graphQLquery, $graphQLvariables='{ }', $api_ver='2023-07')
	{
		// NOTA: Al realizar una petición de tipo application/graphql mediante Guzzle, no es necesario declarar la query
		// CORRECTO: '{ shop { name } }' || INCORRECTO: 'query { shop { name } }'

		// Iniciamos cliente Guzzle con la base URI definida por el selector de tienda y la api_ver especificada.
		$gql_client = new \GuzzleHttp\Client(['base_uri' => "https://".$shopifyStoreSel["url"]."/admin/api/$api_ver/", 'verify' => false]);

		// Petición POST al endpoint de GraphQL en shopify
		$request = $gql_client->post("graphql.json", [
			'headers' => [ 'Content-Type' => 'application/json', 'X-Shopify-Access-Token' => $shopifyStoreSel["pwd"] ],
    		'body' => json_encode(['query' =>  $graphQLquery, 'variables' => json_decode($graphQLvariables)]),
		]);

		// Decodificamos la respuesta
		$response = json_decode((string)$request->getBody(), true);

		// GraphQL devuelve los errores con código 200. Recorremos y parseamos el contenido. Gracias Martín!
		if (isset($response["errors"]))
		{
			$error = array('status' => 'ERROR', 'message' => $response["errors"][0]["message"]);
			return json_encode($error);
		}

		// Output. Array
		return $response;
	}

	public static function shopifyGraphQLCurl($shopifyStoreSel, $graphQLquery, $api_ver='2023-07')
	{
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, "https://".$shopifyStoreSel["url"]."/admin/api/$api_ver/graphql.json");
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
		curl_setopt($ch, CURLOPT_POSTFIELDS, $graphQLquery);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/graphql", "X-Shopify-Access-Token:".$shopifyStoreSel["pwd"]));
		$response = json_decode((string)curl_exec($ch), true);
		curl_close($ch);

		// GraphQL devuelve los errores con código 200. Recorremos y parseamos el contenido. Gracias Martín!
		if (isset($response["errors"]))
		{
			$error = array('status' => 'ERROR', 'type' => $response["errors"][0]["extensions"]["code"], 'message' => $response["errors"][0]["message"]);
			return json_encode($error);
		}

		// Output. Array
		return $response;
	}

	/* @emelero - 16.11.2019 */
	public static function shopifyRESTAPI($method='PUT', $endpoint, $shopifyStoreSel, $data, $api_ver='2023-07')
	{
		// Iniciamos cliente Guzzle con la base URI definida por el selector de tienda y la api_ver especificada.
		$rest_client = new \GuzzleHttp\Client(['base_uri' => "https://".$shopifyStoreSel["url"]."/admin/api/$api_ver/", 'verify' => false]);
		$auth = 'Basic '.base64_encode($shopifyStoreSel["key"].":".$shopifyStoreSel["pwd"]);

		// Establecemos los parámetros en la petición HTTP si es un GET o un DELETE a través de $data
		if ($method == 'GET' || $method == 'DELETE')
		{
			$param = "?".$data;
		}

		// Switch de métodos con error handling. Capturamos los 404 devueltos por la REST API y dibujamos el cuerpo
		// de la respuesta en lugar de disparar una excepción en Laravel.
		switch($method)
		{
			case 'POST': case 'PUT':
				try {
					Log::debug($data);
					$request = $rest_client->request($method, $endpoint, [
						'headers' => [
							'Content-Type' => 'application/json',
							'Authorization' => $auth
						],
						'body' => $data
					]);
				} catch (\GuzzleHttp\Exception\ClientException $e) {
					$error = $e->getResponse()->getBody();
					return json_decode($error->__toString(), true);
				}
				break;
			case 'DELETE': case 'GET':
				try
				{
					$request = $rest_client->request($method, $endpoint.$param, [
						'headers' => [
							'Content-Type' => 'application/json',
							'Authorization' => $auth
						],
					]);
				}
				catch (\GuzzleHttp\Exception\ClientException $e)
				{
					$error = $e->getResponse()->getBody();
					return json_decode($error->__toString(), true);
				}
				break;
			default:
				$error = [
					"errors" => "Invalid method for Request"
				];
				return json_encode($error, true);
		}

		// Decodificamos la respuesta
		$response = json_decode($request->getBody(), true);

		// Output
		return $response;
	}

	public static function retrieveShopifyCustomerByEmail($store, $customerEmail)
    {
        $client = new Client(["base_uri" => "https://" . $store["url"] . "/admin/api/" . env('SFY_API_VER') . "/", 'verify' => false]);
        $auth = "Basic " . base64_encode($store["key"] . ":" . $store["pwd"]);

        try
        {
            $request = $client->request("GET", 'customers/search.json?query=email:"' . $customerEmail .'"', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => $auth,
                ],
            ]);

            $response = $request->getBody();
        } catch (ClientException $e) {
            $error = $e->getResponse()->getBody();
            $response = $error->__toString();
        }

        return $response;
    }

    public static function retrieveShopifyCustomerMetafields($store, $customerId)
    {
        $client = new Client(["base_uri" => "https://" . $store["url"] . "/admin/api/" . env('SFY_API_VER') . "/", 'verify' => false]);
        $auth = "Basic " . base64_encode($store["key"] . ":" . $store["pwd"]);

        try
        {
            $request = $client->request("GET", "customers/" . $customerId . "/metafields.json", [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => $auth,
                ],
            ]);

            $response = $request->getBody();
        } catch (ClientException $e) {
            $error = $e->getResponse()->getBody();
            $response = $error->__toString();
        }

        return $response;
    }

    public static function updateShopifyCustomer($store, $customerId, $data)
    {
        $client = new Client(["base_uri" => "https://" . $store["url"] . "/admin/api/" . env('SFY_API_VER') . "/", 'verify' => false]);
        $auth = "Basic " . base64_encode($store["key"] . ":" . $store["pwd"]);

        try
        {
            $request = $client->request("PUT", "customers/" . $customerId . ".json", [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => $auth,
                ],
                'body' => $data,
            ]);
            $response = $request->getBody();

        } catch (ClientException $e) {
            $error = $e->getResponse()->getBody();
            $response = $error->__toString();
        }
        return $response;
    }

    public static function updateShopifyMetafield($store, $metafieldId, $data)
    {
		// Log::debug("updateShopifyMetafield");
		// Log::debug($store);
		// Log::debug($metafieldId);
		// Log::debug($data);
        $client = new Client(["base_uri" => "https://" . $store["url"] . "/admin/api/" . env('SFY_API_VER') . "/", 'verify' => false]);
        $auth = "Basic " . base64_encode($store["key"] . ":" . $store["pwd"]);

        try
        {
            $request = $client->request("PUT", "metafields/" . $metafieldId . ".json", [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => $auth,
                ],
                'body' => $data,
            ]);
            $response = $request->getBody();
        } catch (ClientException $e) {
            $error = $e->getResponse()->getBody();
            $response = $error->__toString();
        }

        return $response;
    }

	/* @emelero - 16.01.2019 */
	public static function shopifyBulkOperation($shopifyStoreSel, $query)
	{

		$query = 'mutation {
			bulkOperationRunQuery(
			 query: """' . $query . '"""
			) {
			  bulkOperation {
				id
				status
			  }
			  userErrors {
				field
				message
			  }
			}
		  }';

		// Usamos la query y variables definidas en la petición al endpoint de GraphQL
		$main_request = self::shopifyGraphQL($shopifyStoreSel, $query, '{}');

		// En el caso de que accedamos al controlador y ya exista una operación bulk, introducimos
		// un bypass para acceder al while directamente
		$bypass = false;
		if (isset($main_request["data"]["bulkOperationRunQuery"]["userErrors"][0]["message"]))
		{
			if (strpos($main_request["data"]["bulkOperationRunQuery"]["userErrors"][0]["message"], 'already in progress') > -1)
			{
				$bypass = true;
			}
			else
			{
				exit(json_encode($main_request));
			}
		}
		else if (!isset($main_request["data"]))
		{
			exit($main_request);
		}

		// Loop principal. Comprobamos el estado de la petición cada 5 segundos. Si cambia su estado
		// de 'RUNNING', devolvemos el check final
		if ($main_request["data"]["bulkOperationRunQuery"]["bulkOperation"]["status"] == 'CREATED' || $bypass)
		{
			sleep(5); // Esperamos a que CREATED cambie a RUNNING
			do
			{
				$query = 'query { currentBulkOperation { id status errorCode createdAt completedAt objectCount fileSize url partialDataUrl } }';
				$check_request = self::shopifyGraphQL($shopifyStoreSel, $query, '{}');
				if ($check_request["data"]["currentBulkOperation"]["status"] == 'RUNNING') {
					sleep(5);
				}
			} while ($check_request["data"]["currentBulkOperation"]["status"] == 'RUNNING');

			// Output JSONL
			return $check_request;
		}
		else
		{
			return $main_request;
		};
	}

	/*   _  ___      ___   _______   _____
	 *  | |/ / |    /_\ \ / /_ _\ \ / / _ \
	 *  | ' <| |__ / _ \ V / | | \ V / (_) |
	 *  |_|\_\____/_/ \_\_/ |___| |_| \___/
     *
     *  klaviyoTrackApi();
	 */

	// WIP - Método para añadir métricas a los clientes mediante la Track API de Klaviyo
	public static function klaviyoTrackApi($shopifyStoreSel, $event_name, $cust_properties, $properties)
	{
		$ly_client = new \GuzzleHttp\Client(['base_uri' => 'https://a.klaviyo.com/api/track']);

		$body = '{
			"token" : "' . $shopifyStoreSel["klyPublicKey"] . '",
			"event" : "' . $event_name . '",
			"customer_properties" : ' . $cust_properties . ',
			"properties" : ' . $properties . ',
			"time" : ' . date_timestamp_get(date_create()) .'
		}';

		$encoded_body = base64_encode($body);

		try {
			$request = $ly_client->get("?data=$encoded_body");
		} catch (\GuzzleHttp\Exception\ClientException $e) {
			$error = $e->getResponse()->getBody();
			exit($error);
		}

		return json_decode($request->getBody(), true);
	}

	// WIP - Método para añadir propiedades a los clientes mediante la Identify API de Klaviyo
	public static function klaviyoIdentifyApi($shopifyStoreSel, $properties)
	{
		Log::debug("pasa por aquí");
		$ly_client = new \GuzzleHttp\Client(['base_uri' => 'https://a.klaviyo.com/api/identify']);

		$body = '{
			"token" : "' . $shopifyStoreSel["klyPublicKey"] . '",
			"properties" : ' . $properties . '
		}';
		
		$encoded_body = base64_encode($body);
		
		try {
			$request = $ly_client->get("?data=$encoded_body");
		} catch (\GuzzleHttp\Exception\ClientException $e) {
			$error = $e->getResponse()->getBody();
			Log::debug("Something went wrong: " . $e->getMessage() . " at " . $e->getFile() . " : line(" . $e->getLine() . ")");
			exit($error);
		}

		if (strpos($properties, "Accepts Marketing")) {
			$client = new \GuzzleHttp\Client();
			$properties = json_decode($properties, true);
			$mail = $properties['$email'];
			$response = $client->request('POST', 'https://a.klaviyo.com/api/profile-subscription-bulk-create-jobs/', [
			  'body' => '{"data":{"type":"profile-subscription-bulk-create-job","attributes":{"list_id":"Sc5pvc","custom_source":"Marketing Event","subscriptions":[{"channels":{"email":["MARKETING"]},"email":"' . $mail . '"}],"custom_source":"Marketing Event"}}}',
			  'headers' => [
				'Authorization' => 'Klaviyo-API-Key pk_9b103bc31d0db9c7e7a814b0dbea15be91',
				'accept' => 'application/json',
				'content-type' => 'application/json',
				'revision' => '2023-02-22',
			  ],
			]);
		}

		return json_decode($request->getBody(), true);
	}

	// Método para añadir a los usuarios que marcan el consent a una lista
	public static function klaviyoAddProfileToList($mail)
	{
		$client = new Client();
		$headers = [
		  'Authorization' => 'Klaviyo-API-Key ' . $apiKey,
		  'revision' => '2024-05-15'
		];
		$request = new Request('GET', 'https://a.klaviyo.com/api/profiles?filter=equals(email,"' . $request['customer']['email'] . '")', $headers);
		$response = $client->sendAsync($request)->wait();

		$idKlaviyoUser = json_decode($response->getBody(), true);
		$idKlaviyoUser = $idKlaviyoUser['id'];

		$curl = curl_init();

		curl_setopt_array($curl, array(
		  CURLOPT_URL => 'https://a.klaviyo.com/api/lists/YfNW89/relationships/profiles/',
		  CURLOPT_RETURNTRANSFER => true,
		  CURLOPT_ENCODING => '',
		  CURLOPT_MAXREDIRS => 10,
		  CURLOPT_TIMEOUT => 0,
		  CURLOPT_FOLLOWLOCATION => true,
		  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		  CURLOPT_CUSTOMREQUEST => 'POST',
		  CURLOPT_POSTFIELDS =>'{"data":[{"type":"profile","id":"' . $idKlaviyoUser . '"}]}',
		  CURLOPT_HTTPHEADER => array(
			'Authorization: Klaviyo-API-Key pk_b27b9e1b7718a063e615db59b302d6ea4f',
			'revision: 2023-02-22',
			'Content-Type: application/json',
			'Accept: application/json'
		  ),
		));

		$response = curl_exec($curl);

		curl_close($curl);
		echo $response;
	}

	/*   _    _____   ___   _  _______   ___    ___ ___  _  _
	 *  | |  / _ \ \ / /_\ | ||_   _\ \ / / |  |_ _/ _ \| \| |
	 *  | |_| (_) \ V / _ \| |__| |  \ V /| |__ | | (_) | .` |
	 *  |____\___/ |_/_/ \_\____|_|   |_| |____|___\___/|_|\_|
	 *
	 *  getLoyaltyCustomer();
	 *  giveLoyaltyPoints();
	 *  generateSDKAuthToken();
	 *  updateLoyaltyUserBirthday();
	 */

	/* @emelero - 15.11.2019 */
	public static function getLoyaltyCustomer($lty_user_email, $lty_api_key, $lty_api_pwd)
	{

		// Creamos nuevo cliente Guzzle con la URI base de la API de Loyaltylion
		$ly_client = new \GuzzleHttp\Client(['base_uri' => 'https://api.loyaltylion.com/']);

		// Petición GET a través de Guzzle.
		$request = $ly_client->get("v2/customers?email=$lty_user_email", [
			'auth' => [ $lty_api_key, $lty_api_pwd ],
			'headers' => [ 'Accept' => 'application/json' ]
		]);

		// Decodificamos la respuesta
		$response = json_decode($request->getBody(), true);

		//Output. JSON
		return $response;
	}

	/* @emelero - 15.11.2019 */
	public static function giveLoyaltyPoints($merchant_id, $lty_points, $reason, $lty_api_key, $lty_api_pwd)
	{

		// Creamos nuevo cliente Guzzle con la URI base de la API de Loyaltylion
		$ly_client = new \GuzzleHttp\Client(['base_uri' => 'https://api.loyaltylion.com/']);

		// Pedimos a la API de Loyaltylion que añada los puntos al cliente especificado por merchant_id
		$request = $ly_client->post("v2/customers/$merchant_id/points", [
			'auth' => [ $lty_api_key, $lty_api_pwd ],
			'headers' => [ 'Content-Type' => 'application/json' ],
			'json' => [ 'points' => $lty_points, "reason" => $reason ]
		]);

		//return $request->getStatusCode();
	}

	/* @emelero - 07.01.2020 */
	public static function generateSDKAuthToken($cust_id, $cust_email, $lty_api_pwd)
	{

		// $current_date = date(DATE_ISO8601);
		$current_date = date(DATE_ATOM);
		$token = sha1($cust_id . $current_date . $cust_email . $lty_api_pwd);

		//Output. String (SHA-1 encode)
		return $token;
	}

	/* @emelero - 07.01.2020 */
	public static function updateLoyaltyUserBirthday($lty_cust_email, $lty_cust_merchant_id, $birthday_date, $lty_api_key, $lty_api_pwd, $lty_sdk_ver=2){

		$current_date = date(DATE_ISO8601);
		$mac = sha1($lty_cust_merchant_id . $current_date . $lty_cust_email . $lty_api_pwd);

		$auth_packet = base64_encode(urlencode('{"email":"' . $lty_cust_email . '","id":"' . $lty_cust_merchant_id . '","date":"' . $current_date . '","mac":"' . $mac . '"}'));

		$ly_client = new \GuzzleHttp\Client(['base_uri' => 'https://sdk.loyaltylion.net/']);

		try
		{
			$request = $ly_client->post("v2/customers/birthday", [
				'headers' => [
					'Content-Type' => 'application/json',
					'x-auth-packet' => $auth_packet,
					'x-sdk-version' => $lty_sdk_ver,
					'x-site-token' => $lty_api_key,
				],
				'json' => [
					'birthday' => $birthday_date
				]
			]);
		}
		catch (\GuzzleHttp\Exception\ClientException $e)
		{
			$error = $e->getResponse()->getBody();
			exit($error);
		}

		$response = json_decode($request->getBody(), true);
		$response["customer"]["birthday"] = $birthday_date;

		//Output. JSON
		return $response;
	}

	/*    ___  ___   ___   ___ _    ___
	 *   / __|/ _ \ / _ \ / __| |  | __|
	 *  | (_ | (_) | (_) | (_ | |__| _|
	 *   \___|\___/ \___/ \___|____|___| Maps
	 *
     *  googleGeocoding();
	 *  googleDistanceMatrix();
	 */

	/* @emelero - 11.12.2019 */
	public static function googleGeocoding($address, $country_code='ES')
	{
		// Guzzle Client init
		$ly_client = new \GuzzleHttp\Client(['base_uri' => 'https://maps.googleapis.com/maps/api/']);

		// GET
		$request = $ly_client->get("geocode/json?address=$address&components=country:$country_code&key=".env("GOOGLE_API_KEY"));

		// JSON -> ARRAY
		$response = json_decode($request->getBody(), true);

		// Output. Array
		return $response;
	}

	/* @emelero - 11.12.2019 */
	public static function googleDistanceMatrix($origin_address_array, $dest_address_array, $region='ES')
	{
		$origins = implode("|", $origin_address_array);
		$destinations = implode("|", $dest_address_array);

		// Guzzle Client init
		$ly_client = new \GuzzleHttp\Client(['base_uri' => 'https://maps.googleapis.com/maps/api/']);

		// GET
		$request = $ly_client->get("distancematrix/json?origins=$origins&destinations=$destinations&region=$region&key=".env("GOOGLE_API_KEY"));

		// JSON -> ARRAY
		$response = json_decode($request->getBody(), true);

		// Output. Array
		return $response;
	}

	public static function googleDistanceMatrixCurl($origin_address_array, $dest_address_array, $region='ES')
	{
		$origins = str_replace(" ", "+", implode("|", $origin_address_array));
		$destinations = str_replace(" ", "+", implode("|", $dest_address_array));

		// cURL
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, "https://maps.googleapis.com/maps/api/distancematrix/json?origins=$origins&destinations=$destinations&region=$region&key=".env("GOOGLE_API_KEY"));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$response = json_decode(curl_exec($ch), true);
		curl_close($ch);

		// Output. Array
		return $response;
	}

	/*   ___ ___ _   _ ___
	 *  / __| __| | | | _ \
	 *  \__ \ _|| |_| |   /
	 *  |___/___|\___/|_|_\
     *
     *  getSEURExpeditionInfo();
	 */

	 /* @emelero - 13.02.2019 */
	public static function getSEURExpeditionInfo($id='') {

		if (!isset($id)) {
		   exit("Tracking code missing");
	   }

	   // Creamos cliente SOAP con el WS de consulta de expediciones
	   $client = new SoapClient("https://ws.seur.com/webseur/services/WSConsultaExpediciones?wsdl", [ 'cache_wsdl' => WSDL_CACHE_NONE, 'trace' => true, 'exception' => true, 'encoding' => 'UTF-8', 'soap_version' => SOAP_1_1 ]);
	   $parametros = array('in0'=>'L', 'in1'=>'', 'in2'=>'', 'in3'=>$id, 'in4'=>'', 'in5'=>'', 'in6'=>'', 'in7'=>'', 'in8'=>'', 'in9'=>'', 'in10'=>'', 'in11'=>0, 'in12'=>env("SEUR_ES_USER"), 'in13'=>env("SEUR_ES_PWD"), 'in14'=>'S');
	   $response = $client->__soapCall("consultaExpedicionesStr", array('parameters' => $parametros));
	   //$resultado = $client->__getFunctions();

	   // Limpiamos respuesta y creamos xml
	   $cleaner = preg_replace("/(<\/?)(\w+):([^>]*>)/", "$1$2$3", $response->out);
	   $xml = new SimpleXMLElement($cleaner);

	   // Output. JSON.
	   return response()->json($xml, 200, ['Content-Type' => 'application/json;charset=UTF-8', 'Charset' => 'utf-8'], JSON_UNESCAPED_UNICODE);
	}

	/*     _   ___ _____ ___ ___  ___ _  _ ___ ___
     *    /_\ | __|_   _| __| _ \/ __| || |_ _| _ \
     *   / _ \| _|  | | | _||   /\__ \ __ || ||  _/
     *  /_/ \_\_|   |_| |___|_|_\|___/_||_|___|_|
     *
     *  getAfterShipExpeditionInfo();
	 */

	 /* @emelero - 19.05.2019 */
	 public static function getAftershipExpeditionInfo($id='') {

		if (!isset($id)) {
		   exit("Tracking code missing");
	    }

        // Log::debug("id: " . $id);
        // Log::debug(strpos($id, 'T1P'));

		if (strpos($id, 'T1P') === 0 && !strpos($id, '-OUT')) {
            $ly_client = new \GuzzleHttp\Client(['base_uri' => 'https://api.aftership.com/', 'http_errors' => false]);
            $request = $ly_client->get("v4/trackings/spanish-seur-api/$id", [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'aftership-api-key' => '5df8c783-aaac-4c68-b268-aa3317cb4c9b'
                ]
            ]);
        } elseif (strpos($id, 'T1P') === 0 && strpos($id, '-OUT')) {
            $ly_client = new \GuzzleHttp\Client(['base_uri' => 'https://api.aftership.com/', 'http_errors' => false]);
            $request = $ly_client->get("v4/trackings/paack-webhook/$id", [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'aftership-api-key' => '5df8c783-aaac-4c68-b268-aa3317cb4c9b'
                ]
            ]);
        } elseif (strpos($id, '03812F') === 0) {
			$ly_client = new \GuzzleHttp\Client(['base_uri' => 'https://api.aftership.com/', 'http_errors' => false]);
            $request = $ly_client->get("v4/trackings/mrw-spain/$id", [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'aftership-api-key' => '5df8c783-aaac-4c68-b268-aa3317cb4c9b'
                ]
            ]);
		} else {
			// Log::debug("pasa por el else->");
			// Log::debug($id);
            $ly_client = new \GuzzleHttp\Client(['base_uri' => 'https://api.aftership.com/', 'http_errors' => false]);
            $request = $ly_client->get("v4/trackings/correosexpress/$id", [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'aftership-api-key' => '5df8c783-aaac-4c68-b268-aa3317cb4c9b'
                ]
            ]);
        }

		$response = json_decode($request->getBody(), true);
		return $response;
	}

	public static function getCLPostalCode($comuna, $address) {

		if (!isset($comuna)) {
			exit("Comuna missing");
		} else if (!isset($address)) {
			exit("Address missing");
		}

		$ly_client = new \GuzzleHttp\Client(['base_uri' => 'https://www.correos.cl/', 'http_errors' => false, 'verify' => false]);
		$request = $ly_client->post("web/guest/codigo-postal?p_p_id=cl_cch_codigopostal_portlet_CodigoPostalPortlet_INSTANCE_MloJQpiDsCw9&p_p_lifecycle=2&p_p_state=normal&p_p_mode=view&p_p_resource_id=COOKIES_RESOURCE_ACTION&p_p_cacheability=cacheLevelPage&_cl_cch_codigopostal_portlet_CodigoPostalPortlet_INSTANCE_MloJQpiDsCw9_cmd=CMD_ADD_COOKIE", [
			'headers' => [
				'Referer' => 'https://www.correos.cl/web/guest/codigo-postal'
			],
			'form_params' => [
				'_cl_cch_codigopostal_portlet_CodigoPostalPortlet_INSTANCE_MloJQpiDsCw9_comuna' => $comuna,
				'_cl_cch_codigopostal_portlet_CodigoPostalPortlet_INSTANCE_MloJQpiDsCw9_calle' => $address,
				'_cl_cch_codigopostal_portlet_CodigoPostalPortlet_INSTANCE_MloJQpiDsCw9_numero' => 0
			]
		]);

		$response = json_decode($request->getBody(), true);
		return $response;
	}

}
