<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use SoapClient;
use App\Models\AfipCredential;

class AfipController extends Controller
{

    public function constatacion(Request $request){

        $data = $request->validate([
            'cuit_emisor' => 'required|digits:11',
            'tipo_comprobante_code' => 'required|string|max:11',
            'punto_venta' => 'required|max-digits:5',
            'numero' => 'required|max-digits:8',
            'fecha_emision' => 'required|date',
            'importe' => 'required|numeric',
            'tipo_codigo_autorizacion_code' => 'required|string|max:4',
            'codigo_autorizacion' => 'required|digits:14',
            'tipo_documento_receptor' => 'required|max-digits:3',
            'documento_receptor' => 'required|required|max-digits:11',
        ]);

        $ta = AfipCredential::where('service', "wscdc")->whereDate('expirationTime', '>', now())->orWhere(function ($query) use ($request) {
            $query->whereTime('expirationTime', '>', now())->whereDate('expirationTime', '>=', now());
                })->first();
        if(!$ta){
            $ta = $this->login("wscdc");
            if(is_soap_fault($ta)){
                return response()->json($ta->faultstring, Response::HTTP_NOT_FOUND);
            }
        }

        if($ta){
            $results = $this->constatar($data, $ta->token, $ta->sign);
            if(is_soap_fault($results)){
                return response()->json($results->faultstring, Response::HTTP_NOT_FOUND);
            }
            return response()->json(['constatacion' => $results]);
        }
    }

    public function info(Request $request, $cuil){



        $ta = AfipCredential::whereDate('expirationTime', '>', now())->orWhere(function ($query) use ($request) {
            $query->whereTime('expirationTime', '>', now())->whereDate('expirationTime', '>=', now());
                })->first();
        if(!$ta){
            $ta = $this->login();
            if(is_soap_fault($ta)){
                return response()->json($ta->faultstring, Response::HTTP_NOT_FOUND);
            }
        }

        if($ta){
            $results = $this->getPersona($cuil, $ta->token, $ta->sign);
            if(is_soap_fault($results)){
                return response()->json($results->faultstring, Response::HTTP_NOT_FOUND);
            }
            return response()->json(['persona' => $results]);
        }
    }

    private function constatar($comprobante_data, $token, $sign){



        $url = 'https://servicios1.afip.gov.ar/WSCDC/service.asmx';
        $wdsl = 'https://servicios1.afip.gov.ar/WSCDC/service.asmx?WSDL';
        // $url = 'https://wswhomo.afip.gov.ar/WSCDC/service.asmx';
        // $wdsl = 'https://wswhomo.afip.gov.ar/WSCDC/service.asmx?WSDL';

        $client = new \SoapClient($wdsl, array(
            // 'proxy_host'     => PROXY_HOST,
            // 'proxy_port'     => PROXY_PORT,
            // 'soap_version'   => SOAP_1_2,
            'location'       => $url,
            'trace'          => 1,
            'exceptions'     => 0
        ));

        $params = [
            'Auth' => [
                'Token' => $token,
                'Sign'  => $sign,
                'Cuit'  => '20254484149',//$comprobante_data['cuit']
            ],
            'CmpReq' => [
                'CuitEmisor'     => $comprobante_data['cuit_emisor'],
                'CbteModo'       => $comprobante_data['tipo_codigo_autorizacion_code'],
                'PtoVta'         => $comprobante_data['punto_venta'],
                'CbteTipo'       => $comprobante_data['tipo_comprobante_code'],
                'CbteNro'        => $comprobante_data['numero'],
                'CbteFch'        => $comprobante_data['fecha_emision'], // formato YYYYMMDD
                'ImpTotal'       => $comprobante_data['importe'],
                'CodAutorizacion'=> $comprobante_data['codigo_autorizacion'], // o CAEA
                'DocTipoReceptor'=> $comprobante_data['tipo_documento_receptor'], // o CAEA
                'DocNroReceptor' => $comprobante_data['documento_receptor'], // o CAEA
            ]
        ];

        $response = $client->ComprobanteConstatar($params);

        // Para depurar
        // echo "Request:\n" . $client->__getLastRequest() . "\n";
        // echo "Response:\n" . $client->__getLastResponse() . "\n";

        return $response;
    }

    private function getPersona($cuil, $token, $sign){
        $url = 'https://awshomo.afip.gov.ar/sr-padron/webservices/personaServiceA13';
        $wdsl = 'https://awshomo.afip.gov.ar/sr-padron/webservices/personaServiceA13?WSDL';


        $client = new \SoapClient($wdsl, array(
            // 'proxy_host'     => PROXY_HOST,
            // 'proxy_port'     => PROXY_PORT,
            // 'soap_version'   => SOAP_1_2,
            'location'       => $url,
            'trace'          => 1,
            'exceptions'     => 0
        ));

        return $client->getPersona(
            array(  'token' => $token,
                    'sign' => $sign,
                    'cuitRepresentada' => '20254484149',
                    'idPersona' => $cuil
                ));

    }
    
    private function login($SERVICE = "ws_sr_padron_a13")
    {
        $wsdl = storage_path('app/wsaa.wsdl');
        // $cert = Storage::disk('local')->get('MiCertificado.pem');
        // $privateKey = Storage::disk('local')->get('MiClavePrivada.key');
        $cert = Storage::disk('local')->get('produccion.crt');
        $privateKey = Storage::disk('local')->get('produccion.key');

        define("WSDL", $wsdl);
        define("CERT", $cert);
        define("PRIVATEKEY", $privateKey);
        define("PASSPHRASE", "");
        define("PROXY_HOST", "");
        define("PROXY_PORT", "");
        // define("URL", "https://wsaahomo.afip.gov.ar/ws/services/LoginCms");
        define("URL", "https://wsaa.afip.gov.ar/ws/services/LoginCms");


        if (!$SERVICE) {
            return response()->json(['error' => 'Missing service argument'], 400);
        }

        $this->createTRA($SERVICE);
        $CMS = $this->signTRA();
        $res = $this->callWSAA($CMS);

        if(is_soap_fault($res)){
            return $res;
        }

        $xmlTa = new \SimpleXMLElement($res);

        $ta = new AfipCredential();
        $ta->token = $xmlTa->credentials->token;
        $ta->sign = $xmlTa->credentials->sign;
        $ta->expirationTime = $xmlTa->header->expirationTime;
        $ta->service = $SERVICE;

        $ta->save();

        return $ta;
        
    }

    private function createTRA($SERVICE)
    {
        $TRA = new \SimpleXMLElement(
            '<?xml version="1.0" encoding="UTF-8"?>' .
            '<loginTicketRequest version="1.0">' .
            '</loginTicketRequest>'
        );
        $TRA->addChild('header');
        $TRA->header->addChild('uniqueId', date('U'));
        $TRA->header->addChild('generationTime', date('c', date('U') - 60));
        $TRA->header->addChild('expirationTime', date('c', date('U') + 60));
        $TRA->addChild('service', $SERVICE);
        $TRA->asXML('TRA.xml');
    }

    private function SignTRA()
    {
        $STATUS=openssl_pkcs7_sign('TRA.xml', "TRA.tmp", CERT,
            array(PRIVATEKEY, PASSPHRASE),
            array(),
            !PKCS7_DETACHED
            );
        if (!$STATUS) {
            exit("ERROR generating PKCS#7 signature\n");
        }
        $inf=fopen("TRA.tmp", "r");
        $i=0;
        $CMS="";
        while (!feof($inf)) 
            { 
            $buffer=fgets($inf);
            if ( $i++ >= 4 ) {$CMS.=$buffer;}
            }
        fclose($inf);
        #  unlink("TRA.xml");
        unlink("TRA.tmp");
        return $CMS;
    }

    

    private function callWSAA($CMS)
    {
        $client = new \SoapClient(WSDL, array(
            // 'proxy_host'     => PROXY_HOST,
            // 'proxy_port'     => PROXY_PORT,
            // 'soap_version'   => SOAP_1_2,
            'location'       => URL,
            'trace'          => 1,
            'exceptions'     => 0
        ));
        $results =  $client->loginCms(array('in0' => $CMS));
        if (is_soap_fault($results)) {
            return $results;
        }
        return $results->loginCmsReturn;
    }
}
