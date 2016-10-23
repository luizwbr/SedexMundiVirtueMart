<?php

if (!defined('_JEXEC'))
    die('Direct Access to ' . basename(__FILE__) . ' is not allowed.');


/**
 * Shipment plugin for Correios shipments, like regular postal services
 *
 * @version $Id: correios_mundi.php 3220 2011-05-12 20:09:14Z Luizwbr $
 * @package VirtueMart
 * @subpackage Plugins - shipment
 * @copyright Copyright (C) 2004-2011 VirtueMart Team - All rights reserved.
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL, see LICENSE.php
 * VirtueMart is free software. This version may have been modified pursuant
 * to the GNU General Public License, and as distributed it includes or
 * is derivative of works licensed under the GNU General Public License or
 * other free or open source software licenses.
 * See /administrator/components/com_virtuemart/COPYRIGHT.php for copyright notices and details.
 *
 * http://weber.eti.br * @author Luiz Weber
 *
 */

if (!class_exists('vmPSPlugin'))
    require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');

class plgVmShipmentCorreios_Mundi extends vmPSPlugin {

    // instance of class
    public static $_this = false;

    function __construct(& $subject, $config) {
        parent::__construct($subject, $config);
        $this->_loggable = true;
        $this->tableFields = array_keys($this->getTableSQLFields());
        $varsToPush = $this->getVarsToPush ();
        $this->total = 0;        
        $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);        
        $this->erro_site_correios = null;

        $this->correios_total = 0;
        $this->correios_prazo = 0;
    }

    /**
     * Create the table for this plugin if it does not yet exist.
     * @author Valérie Isaksen
     */
    protected function getVmPluginCreateTableSQL() {
        return $this->createTableSQL('Shipment Correios Mundi Table');
    }

    function getTableSQLFields() {
        $SQLfields = array(
            'id' => ' bigint(16) unsigned NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id' => 'int(11) UNSIGNED DEFAULT NULL',
            'order_number' => 'char(32) DEFAULT NULL',
            'virtuemart_shipmentmethod_id' => 'mediumint(1) UNSIGNED DEFAULT NULL',
            'shipment_name' => 'char(255) NOT NULL DEFAULT \'\' ',
            'order_weight' => 'decimal(10,4) DEFAULT NULL',
            'shipment_weight_unit' => 'char(3) DEFAULT \'KG\' ',
            'shipment_cost' => 'decimal(10,2) DEFAULT NULL',
            'shipment_package_fee' => 'decimal(10,2) DEFAULT NULL',
            'tax_id' => 'smallint(1) DEFAULT NULL',
            'prazo' => 'smallint(2) DEFAULT NULL'
        );
        return $SQLfields;
    }



    /**
     * This method is fired when showing the order details in the frontend.
     * It displays the shipment-specific data.
     *
     * @param integer $order_number The order Number
     * @return mixed Null for shipments that aren't active, text (HTML) otherwise
     * @author ValÃ©rie Isaksen
     * @author Max Milbers
     */
    public function plgVmOnShowOrderFEShipment($virtuemart_order_id, $virtuemart_shipmentmethod_id, &$shipment_name) {
        $this->onShowOrderFE($virtuemart_order_id, $virtuemart_shipmentmethod_id, $shipment_name);
    }

    /**
     * This event is fired after the order has been stored; it gets the shipment method-
     * specific data.
     *
     * @param int $order_id The order_id being processed
     * @param object $cart  the cart
     * @param array $priceData Price information for this order
     * @return mixed Null when this method was not selected, otherwise true
     * @author Valerie Isaksen
     */

    function plgVmConfirmedOrder(VirtueMartCart $cart, $order) {
        if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_shipmentmethod_id))) {
            return null; // Another method was selected, do nothing
        }
        if (!$this->selectedThisElement($method->shipment_element)) {
            return false;
        }

        $values['order_number'] = $order['details']['BT']->order_number;
        $values['shipment_id'] = $order['details']['BT']->virtuemart_shipmentmethod_id;
        $values['shipment_name'] = $this->renderPluginName($method);
        $values['order_weight'] = $this->getOrderWeight($cart, $method->weight_unit);
        $values['shipment_weight_unit'] = $method->weight_unit;
        $values['shipment_cost'] = $this->total;
        $values['shipment_package_fee'] = $method->Handling_Fee_CM;
        $values['tax_id'] = $method->tax_id;
        $values['prazo'] = $this->correios_prazo;
        $this->storePSPluginInternalData($values);

        return true;
    }

    /**
     * This method is fired when showing the order details in the backend.
     * It displays the shipment-specific data.
     * NOTE, this plugin should NOT be used to display form fields, since it's called outside
     * a form! Use plgVmOnUpdateOrderBE() instead!
     *
     * @param integer $virtuemart_order_id The order ID
     * @param integer $vendorId Vendor ID
     * @param object $_shipInfo Object with the properties 'shipment' and 'name'
     * @return mixed Null for shipments that aren't active, text (HTML) otherwise
     * @author Valerie Isaksen
     */

    public function plgVmOnShowOrderBEShipment($virtuemart_order_id, $virtuemart_shipmentmethod_id) {
        if (!($this->selectedThisByMethodId($virtuemart_shipmentmethod_id))) {
            return null;
        }
        $html = $this->getOrderShipmentHtml($virtuemart_order_id);
        return $html;
    }


    function getOrderShipmentHtml($virtuemart_order_id) {

        $db = JFactory::getDBO();
        $q = 'SELECT * FROM `' . $this->_tablename . '` '
                . 'WHERE `virtuemart_order_id` = ' . $virtuemart_order_id;
        $db->setQuery($q);
        if (!($shipinfo = $db->loadObject())) {
            vmWarn(500, $q . " " . $db->getErrorMsg());
            return '';
        }

        if (!class_exists('CurrencyDisplay'))
            require(JPATH_VM_ADMINISTRATOR . DS . 'helpers' . DS . 'currencydisplay.php');

        $currency = CurrencyDisplay::getInstance();
        $tax = ShopFunctions::getTaxByID($shipinfo->tax_id);
        $taxDisplay = is_array($tax) ? $tax['calc_value'] . ' ' . $tax['calc_value_mathop'] : $shipinfo->tax_id;
        $taxDisplay = ($taxDisplay == -1 ) ? JText::_('COM_VIRTUEMART_PRODUCT_TAX_NONE') : $taxDisplay;

        $html = '<table class="adminlist">' . "\n";
        $html .=$this->getHtmlHeaderBE();
        $html .= $this->getHtmlRowBE('Método de envio', $shipinfo->shipment_name);
        $html .= $this->getHtmlRowBE('Peso', $shipinfo->order_weight . ' ' . ShopFunctions::renderWeightUnit($shipinfo->shipment_weight_unit));
        $html .= $this->getHtmlRowBE('Valor', $currency->priceDisplay($shipinfo->shipment_cost, '', false));
        $html .= $this->getHtmlRowBE('Custo', $currency->priceDisplay($shipinfo->Handling_Fee_CM, '', false));
        $html .= $this->getHtmlRowBE('Tarifa/Imposto', $taxDisplay);

        if($shipinfo->prazo > 1) {
            $prazoDiasCorreios = ' dias úteis'; 
        } else { 
            $prazoDiasCorreios = ' dia útil'; 
        }
        $prazo = $shipinfo->prazo . $prazoDiasCorreios;

        $html .= $this->getHtmlRowBE('Prazo', $prazo);
        $html .= '</table>' . "\n";

        return $html;
    }


    function _getVendedor($campo, $cart) {
        /*
        // para versões antigas do vm, antes da 2.0.6
        if (!class_exists('VirtuemartModelUser'))
            require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'user.php');
        $vendor = new VirtueMartModelUser();
        $dados = $vendor->getVendor($id);
        foreach ($dados->userInfo as $v){           
            return $v->$campo;
        } */

        $vendor = VmModel::getModel('vendor');
        $userId = $vendor->getUserIdByVendorId($cart->vendorId);

        $usermodel = VmModel::getModel('user');
        $virtuemart_userinfo_id = $usermodel->getBTuserinfo_id($userId);
        $vendorAddress = $usermodel->getUserAddressList($userId, 'BT', $virtuemart_userinfo_id);        

        return $vendorAddress[0]->$campo;
    }

    function _getPreco_site_correios($cart, $method, $cart_prices) {

        //Define medidas e formato da embalagem
        $this->Order_Length = $method->Comprimento_CM;        
        //Largura/Diâmetro médio dos pacotes utilizados para envio pelos Correios(Cm)
        $this->Order_Width = $method->Larg_Diam_CM;        
        //Altura média dos pacotes utilizados para envio pelos Correios(Cm)
        $this->Order_Height = $method->Altura_CM;        

        //Taxa de empacotamento e manuseio, e será acrescida aos custos de envio retornados pelos Correios
        $this->Order_Handling_Fee = $method->Handling_Fee_CM;
        $this->Order_Handling_Fee = floatval(str_replace(",", ".", $this->Order_Handling_Fee));    

        $this->correios_total = 0;
        $this->correios_prazo = 0;

        if ($method->FreteProduto_CM == 1) {

            $product_length = 0;
            $product_width  = 0;    
            $product_height = 0;

            foreach ($cart->products as $k => $product) {
                // converte pra centímetros
                if ($product->product_lwh_uom != 'CM') {
                    $product_length = ShopFunctions::convertDimensionUnit($product->product_length, $product->product_lwh_uom, "CM");
                    $product_width  = ShopFunctions::convertDimensionUnit($product->product_width, $product->product_lwh_uom, "CM");
                    $product_height = ShopFunctions::convertDimensionUnit($product->product_height, $product->product_lwh_uom, "CM");
                } else {
                    $product_height = $product->product_height;
                    $product_width  = $product->product_width;
                    $product_length = $product->product_length;
                }

                if ($product_length < 16) {
                    $product_length = "16";
                }

                if ($product_width < 11) {
                    $product_width = "11";
                }

                if ($product_height < 2) {
                    $product_height = "2";
                }

                $this->Order_Length     = round($product_length,2);               
                $this->Order_Width      = round($product_width,2);
                // $this->Order_Height     = round($product_height * $product->quantity,2);
                $this->Order_Height     = round($product_height,2);

                // converte para kilos
                $product_weight = ShopFunctions::convertWeigthUnit($product->product_weight, $product->product_weight_uom, "G");
                // $peso = $product_weight * $product->quantity;
                $peso = $product_weight;
                //$total_preco = $product->product_price * $product->quantity;
                $total_preco = $product->product_price;

                // sempre pega o maior prazo
                $dados_frete = $this->_parametrosCorreios($total_preco, $method, $peso);

                // pra cada produto, faz a soma de cada produto em separado
                $dados_frete['valor'] = str_replace(",", ".", $dados_frete['valor']);

                $this->correios_total = $dados_frete['valor'] * $product->quantity;
                //$this->correios_total = $dados_frete['valor'];

                // for ($i=0; $i < $product->quantity; $i++) {
                //     $this->correios_total += $dados_frete['valor'];
                // }

                if ($this->correios_prazo < $dados_frete['prazo']) {
                    $this->correios_prazo = $dados_frete['prazo'];
                }
            }            

        } else {

            // calcular o volume total do pedido ( um cálculo por pedido )
            foreach ($cart->products as $k => $product) {

                //Define medidas minimas
                // converte pra centímetros
                $this->Order_Height = 0;          

                if (strtoupper($product->product_lwh_uom) != 'CM') {
                    $product_length = ShopFunctions::convertDimensionUnit($product->product_length, $product->product_lwh_uom, "CM");
                    $product_width  = ShopFunctions::convertDimensionUnit($product->product_width, $product->product_lwh_uom, "CM");
                    $product_height = ShopFunctions::convertDimensionUnit($product->product_height, $product->product_lwh_uom, "CM");
                } else {
                    $product_height = $product->product_height;
                    $product_width  = $product->product_width;
                    $product_length = $product->product_length;
                }

                if ($method->debug) {
                   vmdebug('<b>Dimension Unit - '.$product->virtuemart_product_id.'</b>:', $product->product_lwh_uom);
                   vmdebug('<b>Height - '.$product->virtuemart_product_id.'</b>:', $product_height);
                   vmdebug('<b>Width - '.$product->virtuemart_product_id.'</b>:', $product_width);
                   vmdebug('<b>Length - '.$product->virtuemart_product_id.'</b>:', $product_length);
                }
                
                if ($method->VolumeProduto_CM == '1') {
                    $product_height = round($product_height * $product->quantity,2);
                    if( $product_height > $this->Order_Height){
                        $this->Order_Height += $product_height;
                    }

                    if( $product_width > $this->Order_Width){ 
                        $this->Order_Width = $product_width;
                    }

                    if( $product_length > $this->Order_Length){ 
                        $this->Order_Length = $product_length;
                    }
                } else {

                    if( $product_height > $this->Order_Height){
                        $this->Order_Height = $product_height;
                    }

                    if( $product_width > $this->Order_Width){ 
                        $this->Order_Width = $product_width;
                    }

                    if( $product_length > $this->Order_Length){ 
                        $this->Order_Length = $product_length;
                    }
                }

            }


            // preco total do pedido
            $total_preco = $cart_prices['salesPrice'];    

            $dados_frete = $this->_parametrosCorreios($total_preco,$method, $this->Order_WeightKG);
            $correios_total = str_replace(",", ".", $dados_frete['valor']);

            $this->correios_total = $correios_total;
            $this->correios_prazo = $dados_frete['prazo'];

        }

        if (!empty($method->Prazo_Extra_CM) and $method->Prazo_Extra_CM > 0) {
            $this->correios_prazo += $method->Prazo_Extra_CM;
        }
        
        if ($method->Handling_Fee_CM_type == 'plus') {
            $this->total = $this->correios_total + $this->Order_Handling_Fee;
        } else {
            if ($this->Order_Handling_Fee > 0) {
                $this->total = $this->correios_total * ($this->Order_Handling_Fee);
            } else {
                $this->total = $this->correios_total;
            }
        }
        return;

    }

    function getCosts(VirtueMartCart $cart, $method, $cart_prices) {
        if ($method->free_shipment && $cart_prices['salesPrice'] >= $method->free_shipment) {            
            return 0;            
        } else {
            return $this->total;            
        }
    }

    function getPluginHtml($plugin, $selectedPlugin, $pluginSalesPrice) {

        $pluginmethod_id = $this->_idName;
        $pluginName = $this->_psType . '_name';

        if ($selectedPlugin == $plugin->$pluginmethod_id) {
            $checked = 'checked';
        } else {
            $checked = '';
        }


        if (!class_exists('CurrencyDisplay'))
        require(JPATH_VM_ADMINISTRATOR . DS . 'helpers' . DS . 'currencydisplay.php');
        $currency = CurrencyDisplay::getInstance();
        $costDisplay="";

        $method = $this->getVmPluginMethod($plugin->virtuemart_shipmentmethod_id);

        if ($pluginSalesPrice) {
            // if($this->correios_prazo > 1) {
            //     $prazoDiasCorreios = 'dias úteis'; 
            // } else { 
            //     $prazoDiasCorreios = 'dia útil'; 
            // }
            $costDisplay = "<span class='preco'>".$currency->priceDisplay($pluginSalesPrice)."</span>";

            $printPrazo = '';
            if ($method->MostrarPrazoEntrega_CM == '1') {
                if (!empty($this->correios_prazo) or $this->correios_prazo == '') {
                    $printPrazo = '( Entrega Aprox. ' . $this->correios_prazo . ' )';
                }
            }

            $pesoCorreios = '';
            if ($method->MostrarPeso_CM == '1') {
                $pesoCorreios = '( '. $this->Order_WeightKG / 1000 . 'Kg )';                
            }

            $costDisplay ='<span class="' . @$this->_type . '_cost"> (' . JText::_('COM_VIRTUEMART_PLUGIN_COST_DISPLAY') . $costDisplay . ') '.$pesoCorreios.' '.$printPrazo.'</span>';
        } else {
            $costDisplay ='<span class="' . @$this->_type . '_cost"> (Frete Grátis) '.$pesoCorreios.' '.$printPrazo.'</span>';
        }

        if (!empty($this->erro_site_correios)) {
            $html = '<span class="' . $this->_type . '">' . $plugin->$pluginName . ' - Erro: '. $this->erro_site_correios . "</span>\n";
        } else {
            $html = '<input type="radio" name="' . $pluginmethod_id . '" id="' . $this->_psType . '_id_' . $plugin->$pluginmethod_id . '"   value="' . $plugin->$pluginmethod_id . '" ' . $checked . ">\n"
            . '<label for="' . $this->_psType . '_id_' . $plugin->$pluginmethod_id . '">' . '<span class="' . $this->_type . '">' . $plugin->$pluginName . $costDisplay."</span></label>\n<br style='clear:both' />";
        }
        return $html;
    }

    function _parametrosCorreios($total_preco, $method, $peso) {

        if ($this->Order_Length < 16) {
            $this->Order_Length = "16";
        }

        if ($this->Order_Width < 11) {
            $this->Order_Width = "11";
        }

        if ($this->Order_Height < 2) {
            $this->Order_Height = "2";
        }
       
        $workstring ='tipoConsulta=Geral';
        $workstring.='&ESPECIF='. $method->Servicos_CM;        
        $workstring.='&PESO=' . round($peso);

        $workstring.='&PROFUNDIDADE=' . round($this->Order_Length);
        $workstring.='&LARGURA=' . round($this->Order_Width);
        $workstring.='&ALTURA=' . round($this->Order_Height);

        $workstring.='&PAIS=' . $this->Pais_destino;
        $workstring.='&RESET=TRUE';
        $workstring.='&UFORIGEM='. $method->Uforigem_CM;
        $workstring.='&LOCALIDADE='. $method->Localidade_CM;
        
        $url_busca = "http://www2.correios.com.br/sistemas/efi/bb/Consulta.cfm";

        $url_busca .= "?" . $workstring;
        if ($method->debug) {
            vmdebug('<b>Debug Correios - Url</b>: <a target="_blank" href="'.$url_busca.'">Serviço: <b>'.$method->Servicos_CM.'</b></a><br/>');
        }
        $dados_frete = $this->_xmlCorreios($url_busca);
        return $dados_frete;

    }

    function _xmlCorreios($url) {
        $this->erro_site_correios = null;
        if(ini_get('allow_url_fopen') == '1') {
            $conteudo = @file_get_contents(str_replace('&amp;','&',$url)); // Usa file_get_contents() 
            if($conteudo === false) {
              //echo "$nome_servico: Sistema Indisponível";
              $this->erro_site_correios = "Erro Correios: N&atilde;o foi poss&iacute;vel conectar ao site dos correios, tente novamente mais tarde.";
              return false;
            }

        } else {
            if (function_exists('curl_init')) {
               $ch = curl_init();
               curl_setopt($ch, CURLOPT_URL, $url); 
               curl_setopt($ch, CURLOPT_HEADER, 0); 
               curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 
               curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.7.5) Gecko/20041107 Firefox/1.0'); 
               $conteudo = curl_exec($ch); 
               $curl_erro = curl_errno($ch);
               if(curl_errno($ch) != 0) {
                  return false;
               }
               curl_close($ch);
            } else {
               $this->erro_site_correios = "Erro Correios: N&atilde;o foi poss&iacute;vel conectar ao site dos correios, tente novamente mais tarde."; 
               return false;
            }
        }

        if (class_exists('DOMDocument')) {
            $xml = new DOMDocument();
            @$xml->loadXML($conteudo);

            $elementoMae = $xml->getElementsByTagName('dados_postais')->item(0);
            $elementoErro = $xml->getElementsByTagName('erro')->item(0);
            if (isset($elementoMae)) {
                $valorFrete  = $xml->getElementsByTagName('preco_postal')->item(0)->nodeValue;
                $prazoEntrega      = $xml->getElementsByTagName('prazo_entrega')->item(0)->nodeValue;
                $msgerro = $elementoMae->getElementsByTagName("descricao")->item(0)->nodeValue;
                $erro = $elementoMae->getElementsByTagName("codigo")->item(0)->nodeValue;
            } else {
                $msgerro = "Erro no XML dos Correios";
                $erro = true;
            }

       } else {
            $xml = @simplexml_load_string($conteudo);
            // usa o simple xml file
            $valorFrete = @$xml->{'dados_postais'}->preco_postal;
            $prazoEntrega = @$xml->{'dados_postais'}->prazo_entrega;
            $msgerro = @$xml->{'erro'}->descricao;
            $erro = @$xml->{'erro'}->codigo;
            $this->erro_site_correios = "Erro Correios: N&atilde;o foi poss&iacute;vel conectar ao site dos correios, tente novamente mais tarde.";
            return false;
        }

        //if (Calculo_produto_sedex == 1) {
        $valor  = str_replace(',','.',$valorFrete);

        if ($erro != '' and $erro != '0' and ($valor == 0 or $valor == '')) {
            $this->erro_site_correios = "Erro no Webservice Correios Correios: " . $msgerro;
            return false;
        }




        return array(
            "valor"     => $valorFrete,
            "prazo"     => $prazoEntrega,
            "erro"      => $erro,
            "msgErro"   => $msgerro
        );
    }

    protected function checkConditions($cart, $method, $cart_prices) {



        $this->convert ($method);
        $mainframe = JFactory::getApplication();

        $address = (($cart->ST == 0) ? $cart->BT : $cart->ST);

        //verificar se está no Brasil
        if (isset($address["virtuemart_country_id"]) and $address["virtuemart_country_id"] == 30) {
            // $mainframe->enqueueMessage('Correios erro: Somente para entregas o Brasil');
            return false;
        }

        if (isset($address["virtuemart_country_id"])) {

	        $db     = JFactory::getDbo();
	        $sql    = "SELECT country_2_code
	                    FROM `#__virtuemart_countries`
	                    WHERE virtuemart_country_id = ".$address["virtuemart_country_id"];
	        $db->setQuery($sql);
	        $consulta = $db->loadObjectList(); 

	        $this->Pais_destino = $consulta[0]->country_2_code; // $cart->ST["virtuemart_country_id"];

	        $view = vRequest::getVar('view');
	        if (!count($cart->products) and $view == 'cart') {            
	            return false;
	        }
        }

        // Verifica se o peso está dentro dos limites
        //não precisa estar logado
        $this->Order_WeightKG = $orderWeight = $this->getOrderWeight($cart, "G");              
        if ($method->debug) {            
            vmdebug('Weight:',$orderWeight);
            vmdebug('Unit: ',$method->weight_unit);

            foreach($cart->products as $produto) {
                vmdebug('produto: '.$produto->product_sku,$produto->product_weight_uom);     
            }

        }

        // if ($this->Order_WeightKG > 50000 and $method->FreteProduto_CM == 0) {
        //     if ($method->MensagemErro_CM )
        //         $mainframe->enqueueMessage("Correios erro: o peso de " . $this->Order_WeightKG . " Kg excede o peso m&aacute;ximo (50 Kg).");
        //     return false;
        // } elseif ($this->Order_WeightKG == 0) {
        //     if ($method->MensagemErro_CM )
        //         $mainframe->enqueueMessage("Correios: o peso de " . $this->Order_WeightKG . " Kg sugere produto(s) para DownLoad.");
        //     return false;
        // } elseif ($this->Order_WeightKG < 0.01) {
        //     $this->Order_WeightKG = 0.01;
        // }
       
        $nbShipment = 0;
        $countries = array();
        if (!empty($method->countries)) {
            if (!is_array($method->countries)) {
                $countries[0] = $method->countries;
            } else {
                $countries = $method->countries;
            }
        }


        // probably did not gave his BT:ST address
        if (!is_array($address)) {
            $address = array();
            $address['zip'] = 0;
            $address['virtuemart_country_id'] = 0;
        }

        // $weight_cond = $this->_weightCond($orderWeight, $method);
        // verifica pelo peso total do pedido
        $weight_cond = $this->testRange($orderWeight,$method,'weight_start','weight_stop','weight');
        if (!$weight_cond) {
            $weight_cond = false;
        }
        // verifica pelo número de produtos
        $nbproducts_cond = $this->_nbproductsCond ($cart, $method);
        if (!$nbproducts_cond) {
            $nbproducts_cond = false;
        }

        // verifica pelo valor total do pedido
        if(isset($cart_prices['salesPrice'])){
            $orderamount_cond = $this->testRange($cart_prices['salesPrice'],$method,'orderamount_start','orderamount_stop','order amount');
        } else {
            $orderamount_cond = false;
        }

        $userFieldsModel =VmModel::getModel('Userfields');
        $type = '';

        // verifica o país
        if ($userFieldsModel->fieldPublished('virtuemart_country_id', $type)){
            if (!isset($address['virtuemart_country_id'])) {
                $address['virtuemart_country_id'] = 0;
            }

            if (in_array ($address['virtuemart_country_id'], $countries) || count ($countries) == 0) {

                //vmdebug('checkConditions '.$method->shipment_name.' fit ',$weight_cond,(int)$zip_cond,$nbproducts_cond,$orderamount_cond);
                vmdebug('shipmentmethod '.$method->shipment_name.' = TRUE for variable virtuemart_country_id = '.implode($countries,', ').', Reason: Country in rule or none set');
                $country_cond = true;
            }
            else{
                vmdebug('shipmentmethod '.$method->shipment_name.' = FALSE for variable virtuemart_country_id = '.implode($countries,', ').', Reason: Country does not fit');
                $country_cond = false;
            }
        } else {
            vmdebug('shipmentmethod '.$method->shipment_name.' = TRUE for variable virtuemart_country_id, Reason: no boundary conditions set');
            $country_cond = true;
        }

        // verifica todas as condições no final
        if ($weight_cond and $nbproducts_cond and $orderamount_cond and $country_cond) {

            // buscar info do site dos correios 
            $this->_getPreco_site_correios($cart, $method, $cart_prices);

            if ($this->erro_site_correios) {
                if ($method->MensagemErro_CM )
                    $mainframe->enqueueMessage($this->erro_site_correios);
                return false;
            }

            return true;
        }

        return false;
    }

    /**
     * @param $method
     */
    function convert (&$method) {

        //$method->weight_start = (float) $method->weight_start;
        //$method->weight_stop = (float) $method->weight_stop;
        $method->orderamount_start = (float)$method->orderamount_start;
        $method->orderamount_stop = (float)$method->orderamount_stop;
        // $method->CepStart_CM = (int)$method->CepStart_CM;
        // $method->CepEnd_CM = (int)$method->CepEnd_CM;
        $method->nbproducts_start = (int)$method->nbproducts_start;
        $method->nbproducts_stop = (int)$method->nbproducts_stop;
        $method->free_shipment = (float)$method->free_shipment;
    }


    private function _nbproductsCond ($cart, $method) {

        if (!isset($method->nbproducts_start) and !isset($method->nbproducts_stop)) {
            vmdebug('_nbproductsCond',$method);
            return true;
        }

        $nbproducts = 0;
        foreach ($cart->products as $product) {
            $nbproducts += $product->quantity;
        }

        if ($nbproducts) {

            $nbproducts_cond = $this->testRange($nbproducts,$method,'nbproducts_start','nbproducts_stop','products quantity');

        } else {
            $nbproducts_cond = false;
        }

        return $nbproducts_cond;
    }




    private function testRange($value, $method, $floor, $ceiling,$name){

        $cond = true;
        if(!empty($method->$floor) and !empty($method->$ceiling)){
            $cond = (($value >= $method->$floor AND $value <= $method->$ceiling));
            if(!$cond){
                $result = 'FALSE';
                $reason = 'is NOT within Range of the condition from '.$method->$floor.' to '.$method->$ceiling;
            } else {
                $result = 'TRUE';
                $reason = 'is within Range of the condition from '.$method->$floor.' to '.$method->$ceiling;
            }
        } else if(!empty($method->$floor)){
            $cond = ($value >= $method->$floor);
            if(!$cond){
                $result = 'FALSE';
                $reason = 'is not at least '.$method->$floor;
            } else {
                $result = 'TRUE';
                $reason = 'is over min limit '.$method->$floor;
            }
        } else if(!empty($method->$ceiling)){
            $cond = ($value <= $method->$ceiling);
            if(!$cond){
                $result = 'FALSE';
                $reason = 'is over '.$method->$ceiling;
            } else {
                $result = 'TRUE';
                $reason = 'is lower than the set '.$method->$ceiling;
            }
        } else {
            $result = 'TRUE';
            $reason = 'no boundary conditions set';
        }

        vmdebug('shipmentmethod '.$method->shipment_name.' = '.$result.' for variable '.$name.' = '.$value.' Reason: '.$reason);
        return $cond;
    }


    /*

     * We must reimplement this triggers for joomla 1.7

     */



    /**
     * Create the table for this plugin if it does not yet exist.
     * This functions checks if the called plugin is active one.
     * When yes it is calling the standard method to create the tables
     * @author ValÃ©rie Isaksen
     *
     */

    function plgVmOnStoreInstallShipmentPluginTable($jplugin_id) {

        return $this->onStoreInstallPluginTable($jplugin_id);

    }



    /**
     * This event is fired after the shipment method has been selected. It can be used to store
     * additional payment info in the cart.
     *
     * @author Max Milbers
     * @author ValÃ©rie isaksen
     *
     * @param VirtueMartCart $cart: the actual cart
     * @return null if the payment was not selected, true if the data is valid, error message if the data is not vlaid
     *
     */

    public function plgVmOnSelectCheck($psType, VirtueMartCart $cart) {

        return $this->OnSelectCheck($psType, $cart);

    }



    /**
     * plgVmDisplayListFE
     * This event is fired to display the pluginmethods in the cart (edit shipment/payment) for exampel
     *
     * @param object $cart Cart object
     * @param integer $selected ID of the method selected
     * @return boolean True on succes, false on failures, null when this plugin was not selected.
     * On errors, JError::raiseWarning (or JError::raiseError) must be used to set a message.
     *
     * @author Valerie Isaksen
     * @author Max Milbers
     */

    public function plgVmDisplayListFEShipment(VirtueMartCart $cart, $selected = 0, &$htmlIn) {
        return $this->displayListFE($cart, $selected, $htmlIn);
    }



    /*
     * plgVmonSelectedCalculatePrice
     * Calculate the price (value, tax_id) of the selected method
     * It is called by the calculator
     * This function does NOT to be reimplemented. If not reimplemented, then the default values from this function are taken.
     * @author Valerie Isaksen
     * @cart: VirtueMartCart the current cart
     * @cart_prices: array the new cart prices
     * @return null if the method was not selected, false if the shiiping rate is not valid any more, true otherwise
     *
     *
     */
    public function plgVmonSelectedCalculatePriceShipment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) {
        return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    /**
     * plgVmOnCheckAutomaticSelected
     * Checks how many plugins are available. If only one, the user will not have the choice. Enter edit_xxx page
     * The plugin must check first if it is the correct type
     * @author Valerie Isaksen
     * @param VirtueMartCart cart: the cart object
     * @return null if no plugin was found, 0 if more then one plugin was found,  virtuemart_xxx_id if only one plugin is found
     *
     */
    function plgVmOnCheckAutomaticSelectedShipment(VirtueMartCart $cart, array $cart_prices = array()) {
        return $this->onCheckAutomaticSelected($cart, $cart_prices);
    }

    /**
     * This event is fired during the checkout process. It can be used to validate the
     * method data as entered by the user.
     *
     * @return boolean True when the data was valid, false otherwise. If the plugin is not activated, it should return null.
     * @author Max Milbers

    public function plgVmOnCheckoutCheckData($psType, VirtueMartCart $cart) {
      return null;
    }
     */

    /**
     * This method is fired when showing when priting an Order
     * It displays the the payment method-specific data.
     *
     * @param integer $_virtuemart_order_id The order ID
     * @param integer $method_id  method used for this order
     * @return mixed Null when for payment methods that were not selected, text (HTML) otherwise
     * @author Valerie Isaksen
     */

    function plgVmonShowOrderPrint($order_number, $method_id) {
        return $this->onShowOrderPrint($order_number, $method_id);
    }

    /**
     * Save updated order data to the method specific table
     *
     * @param array $_formData Form data
     * @return mixed, True on success, false on failures (the rest of the save-process will be
     * skipped!), or null when this method is not actived.
     * @author Oscar van Eijk
      public function plgVmOnUpdateOrder($psType, $_formData) {
      return null;
      }
     */

    /**
     * Save updated orderline data to the method specific table
     *
     * @param array $_formData Form data
     * @return mixed, True on success, false on failures (the rest of the save-process will be
     * skipped!), or null when this method is not actived.
     * @author Oscar van Eijk
      public function plgVmOnUpdateOrderLine($psType, $_formData) {
      return null;
      }
     */

    /**
     * plgVmOnEditOrderLineBE
     * This method is fired when editing the order line details in the backend.
     * It can be used to add line specific package codes
     *
     * @param integer $_orderId The order ID
     * @param integer $_lineId
     * @return mixed Null for method that aren't active, text (HTML) otherwise
     * @author Oscar van Eijk
      public function plgVmOnEditOrderLineBE($psType, $_orderId, $_lineId) {
      return null;
      }
     */

    /**
     * This method is fired when showing the order details in the frontend, for every orderline.
     * It can be used to display line specific package codes, e.g. with a link to external tracking and
     * tracing systems
     *
     * @param integer $_orderId The order ID
     * @param integer $_lineId
     * @return mixed Null for method that aren't active, text (HTML) otherwise
     * @author Oscar van Eijk
      public function plgVmOnShowOrderLineFE($psType, $_orderId, $_lineId) {
      return null;
      }
     */



    /**
     * plgVmOnResponseReceived
     * This event is fired when the  method returns to the shop after the transaction
     *
     *  the method itself should send in the URL the parameters needed
     * NOTE for Plugin developers:
     *  If the plugin is NOT actually executed (not the selected payment method), this method must return NULL
     *
     * @param int $virtuemart_order_id : should return the virtuemart_order_id
     * @param text $html: the html to display
     * @return mixed Null when this method was not selected, otherwise the true or false
     *
     * @author Valerie Isaksen
     *
      function plgVmOnResponseReceived($psType, &$virtuemart_order_id, &$html) {
      return null;
      }
     */

    function plgVmDeclarePluginParamsShipment ($name, $id, &$dataOld) {
        return $this->declarePluginParams ('shipment', $name, $id, $dataOld);
    }

    function plgVmDeclarePluginParamsShipmentVM3 (&$data) {
        return $this->declarePluginParams ('shipment', $data);
    }



    function plgVmSetOnTablePluginParamsShipment($name, $id, &$table) {
        return $this->setOnTablePluginParams($name, $id, $table);
    }

    /**
     * @param $name
     * @param $id
     * @param $data
     * @return bool
     */


}
// No closing tag
