<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Utilidades;
use Illuminate\Http\Request;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ClientException;
use DateTime;
use Illuminate\Support\Facades\Log;

class ShopifyExtendedRegisterController extends Controller
{
    // Extra fields to process from form
    const METAFIELDS = ["birthday_date", "zip", "postal_code", "phone_number", "gender", "country"];

    // Create a new user or send an invite to the user if the email exists on Shopify and the user has no account
    public function registerNewUser(Request $request) {

        // Definitions
        $customer = $request->all()['customer'];
        if (isset($request->all()['interest'])) {
            $interests = $request->all()['interest'];
        }
        $originurl = str_replace('https://', '', $request->headers->get('origin'));
        $enabledstores = env('SFY_ENABLED_STORES');
        $response = '';

        // Origin Control
        $check = $this->checkOrigin($enabledstores, $originurl);
        if (empty($check)) {
            return response('Forbidden', 403)->header('Content-Type', 'text/plain');
        } else {
            $origincode = $check;
        }

        // Phone number space trimming
        if (isset($customer['phone_number'])) {
            $customer['phone_number'] = str_replace(' ', '', $customer['phone_number']);
        }

        // Base User
        $user = [
            "customer" => [
                "first_name" => $customer['first_name'],
                "last_name" => $customer['last_name'],
                "password" => $customer['password'],
                "password_confirmation" => $customer['password'],
                "email" => $customer['email'],
                //"tags" =>  ["nofid"],
                "metafields" => []
            ]
        ];

        // Klaviyo properties
        $properties = [
            '$email' => $customer['email'],
            '$first_name' => $customer['first_name'],
            '$last_name' => $customer['last_name']
        ];

        if (isset($customer['accepts_marketing'])) {
            $user["customer"]["accepts_marketing"] = $customer['accepts_marketing'];
            $properties["Accepts Marketing"] = $customer['accepts_marketing'];
            // Interests
            if ($customer['accepts_marketing'] == true && isset($interests)) {
                $properties["InteresesNewsletter"] = [];
                foreach ($interests as $name => $value) {
                    array_push($user["customer"]["metafields"], [
                        "key" => 'interest_'.$name,
                        "value" => $value,
                        "type" => "string",
                        "namespace" => "customr"
                    ]);
                    switch ($name) {
                        case 'man':
                            array_push($properties["InteresesNewsletter"], "Int_Hombre");
                            break;
                        case 'woman':
                            array_push($properties["InteresesNewsletter"], "Int_Mujer");
                            break;
                    }
                }
            }
        }

        // Extra User Fields
        foreach ($customer as $name => $value) {
            if (in_array($name, self::METAFIELDS) && $value != null) {
                array_push($user["customer"]["metafields"], [
                    "key" => $name,
                    "value" => $value,
                    "type" => "string",
                    "namespace" => "customr"
                ]);
                switch ($name) {
                    case "birthday_date":
                        try {
                            $time2 = new DateTime($value);
                            $properties['birthday'] = $time2->format('Y-m-d H:i:s');
                        } catch(\Exception $e) {
                            //Log::warning($e->getMessage());
                        }
                        break;
                    case "zip":
                        $properties["postal_code"] = $value;
                        break;
                    case "phone_number":
                        $properties['$phone_number'] = $value;
                        break;
                    case "gender":
                        $properties["gender"] = $value;
                        break;
                    case "country":
                        $properties['$country'] = $value;
                        break;
                }
            }
        }

        // Check Customer (send invite if it's disabled and stop controller)
        $sfy_customer_get = Utilidades::shopifyRESTAPI('GET', 'customers/search.json?query=email:'.$customer['email'], Utilidades::shopifyStoreSel($origincode), "");
        if ($sfy_customer_get["customers"] != null) {
            if ($sfy_customer_get["customers"][0]["state"] == "disabled") {
                $invite = Utilidades::shopifyRESTAPI('POST', 'customers/' . $sfy_customer_get["customers"][0]["id"] . '/send_invite.json', Utilidades::shopifyStoreSel($origincode), '{ "customer_invite": {} }');
                if (isset($invite["customer_invite"])) {
                    // Update customer fields after sending the invite and before returning status
                    $cust_array = $request->customer;
                    $cust_array["id"] = $sfy_customer_get["customers"][0]["id"];
                    $request->merge(["customer" => $cust_array]);
                    $this->updateUser($request);
                    $response = response('Invite Sent', 202)->header('Content-Type', 'text/plain');
                    Utilidades::klaviyoIdentifyApi(Utilidades::shopifyStoreSel($origincode), json_encode($properties, true));
                }
            } else {
                $response = response('Already Exists', 409)->header('Content-Type', 'text/plain');
            }
        } else {
            // Create user
            $sfy_customer = Utilidades::shopifyRESTAPI('POST', 'customers.json', Utilidades::shopifyStoreSel($origincode), json_encode($user, true));
            if (isset($sfy_customer["customer"])) {
                $response = response('Created', 201)->header('Content-Type', 'text/plain');
                // Notify to Klaviyo
                Utilidades::klaviyoIdentifyApi(Utilidades::shopifyStoreSel($origincode), json_encode($properties, true));
            } else if (isset($sfy_customer["errors"])) {
                $response = response($sfy_customer["errors"], 400)->header('Content-Type', 'text/plain');
            }
        }

        // Output (HTTP Status + plain text message)
        Log::debug($response);
        return $response;
    }

    // Update fields of a existent user
    public function updateUser(Request $request) {

        // Definitions
        $customer = $request->all()['customer'];
        $originurl = str_replace('https://', '', $request->headers->get('origin'));
        $enabledstores = env('SFY_ENABLED_STORES');
        $currentmetafields = [];
        $response = '';

        // Origin Control
        $check = $this->checkOrigin($enabledstores, $originurl);
        if (empty($check)) {
            return response('Forbidden', 403)->header('Content-Type', 'text/plain');
        } else {
            $origincode = $check;
        }

        // Phone number space trimming
        if (isset($customer['phone_number'])) {
            $customer['phone_number'] = str_replace(' ', '', $customer['phone_number']);
        }

        // Check if Customer ID is in the request
        if (!isset($customer['id'])) {
            return response('Missing ID', 400)->header('Content-Type', 'text/plain');
        }

        // Getting customer object and associated metafields array
        $sfy_customer_full = Utilidades::shopifyRESTAPI('GET', 'customers/' . $customer['id'] . '.json', Utilidades::shopifyStoreSel($origincode), "");
        if (isset($sfy_customer_full["errors"])) {
            if ($sfy_customer_full["errors"] == 'Not Found') {
                return response('Customer not found', 404)->header('Content-Type', 'text/plain');
            }
        } else {
            $sfy_customer_metafields = Utilidades::shopifyRESTAPI('GET', 'customers/' . $customer['id'] . '/metafields.json', Utilidades::shopifyStoreSel($origincode), "namespace=customr");
        }

        // Klaviyo properties
        $properties = [
            '$email' => $sfy_customer_full['customer']['email']
        ];

        // Base User
        $user = [
			"customer" => [
				"id" => $customer['id'],
				"email" => $sfy_customer_full['customer']['email'],
				"metafields" => []
			]
        ];

        if (isset($customer['first_name'])) {
            $user["customer"]["first_name"] = $customer['first_name'];
        }

        if (isset($customer['last_name'])) {
            $user["customer"]["last_name"] = $customer['last_name'];
        }

        if (isset($customer['postal_code'])) {
            $properties["postal_code"] = $customer['postal_code'];
        }

        if (isset($customer['country'])) {
            $properties['$country'] = $customer['country'];
        }

        if (isset($customer['phone_number'])) {
            $properties['$phone_number'] = $customer['phone_number'];
        }

        if (isset($customer['gender'])) {
            $properties['gender'] = $customer['gender'];
        }

        if (isset($customer['birthday_date'])) {
            try {
                $time2 = new DateTime($customer['birthday_date']);
                $properties['birthday'] = $time2->format('Y-m-d H:i:s');
            } catch(\Exception $e) {
                //Log::warning($e->getMessage());
            }
        }

        if (isset($sfy_customer_metafields["metafields"])) {

            // Replace existing values if field value is different
            foreach ($sfy_customer_metafields["metafields"] as $key => $value) {
                $metafield = $sfy_customer_metafields["metafields"][$key]["key"];
                $value = $sfy_customer_metafields["metafields"][$key]["value"];
                $namespace = $sfy_customer_metafields["metafields"][$key]["namespace"];
                if (isset($customer[$metafield])) {
                    if (in_array($metafield, self::METAFIELDS) && $value != $customer[$metafield]) {
                        $updatemeta = Utilidades::shopifyRESTAPI('PUT', 'metafields/' . $sfy_customer_metafields["metafields"][$key]["id"] . '.json', Utilidades::shopifyStoreSel($origincode), '{ "metafield": {"value": "' . $customer[$metafield] . '" } }');
                    }
                }
                if ($namespace == 'customr') {
                    array_push($currentmetafields, $metafield);
                }
            }

            // Append new metafields to Base User if they don't exist already
            foreach ($customer as $name => $value) {
                if (in_array($name, self::METAFIELDS) && !in_array($name, $currentmetafields) && $value != null) {
                    array_push($user["customer"]["metafields"], [
                        "key" => $name,
                        "value" => $value,
                        "type" => "string",
                        "namespace" => "customr"
                    ]);
                }
            }

            // Update User
            $createmeta = Utilidades::shopifyRESTAPI('PUT', 'customers/' . $customer['id'] . '.json', Utilidades::shopifyStoreSel($origincode), json_encode($user, true));
            Utilidades::klaviyoIdentifyApi(Utilidades::shopifyStoreSel($origincode), json_encode($properties, true));

           if ((!isset($updatemeta)) && (!isset($createmeta["customer"]["id"]))) {
                $response = response('Server error', 500)->header('Content-Type', 'text/plain');
            } else if (isset($createmeta["customer"]["id"])){
                $response = response('Customer updated', 200)->header('Content-Type', 'text/plain');
            }

        } else {
            $response = response('Server error', 500)->header('Content-Type', 'text/plain');
        }

        // Output (HTTP Status + plain text message)
        return $response;
    }

    private function checkOrigin($storeurlarray, $originurl) {
        $origincode = '';
        foreach (explode(',', $storeurlarray) as $storecode) {
            if ((env('SFY_' . $storecode . '_STORE_URL') == $originurl) || (env('SFY_' . $storecode . '_STORE_DOMAIN') == $originurl)) {
                $origincode = $storecode;
                break;
            }
        }
        return $origincode;
    }
}
