<?php

/**
 * Connects with West Point Products for order fullfillment
 * 
 * @copyright  Copyright (c) 2014 E-Moxie Data Solutions, Inc.
 * @author     Matt Pramschufer <matt@emoxie.com>

 * @version    Release: 1.0
 * @since      Class available since Release 1.0
 * 
 * Example:
 * $processFullfillment = new WestpointProducts($orderId);

 */
class WestpointProducts {

    protected $POSTURL = 'http://www.westpointproducts.com/ci/CustomerIntegration.aspx';
    protected $USERID = '';
    protected $COMPANYCD = '';
    protected $SENDERID = '';
    protected $PASSWORD = '';
    protected $ORDERID;
    protected $SUPPORTEMAIL = '';

    public function __construct($id) {

        $this->ORDERID = $id;
        $this->retrieveOrder();
    }

    public function retrieveOrder() {

        $mysql = db();
        $fetchOrder = $mysql->query('SELECT * FROM .orders WHERE `id` = ' . $this->smartquote($this->ORDERID));
        $orderData = $fetchOrder->fetch_array(MYSQLI_ASSOC);

        $fetchItems = $mysql->query('SELECT * FROM .items WHERE `order_id` = ' . $this->smartquote($this->ORDERID));

        while ($row = $fetchItems->fetch_array(MYSQLI_ASSOC)) {
            $itemsData[$row['id']] = array('product_id' => $row['product_id'],
                'title' => $row['title'],
                'skew' => $row['skew'],
                'price' => $row['price'],
                'quantity' => $row['quantity']
            );
        }

        $orderDetails = $orderData;
        $orderDetails['items'] = $itemsData;

        $this->ORDER = $orderDetails;

        $this->prepareXml();
    }

    public function prepareXml() {
        $xml = '<?xml version="1.0" encoding="ISO-8859-1"?>
            <OrderSubmit>
              <Credential>
                <UserID>' . $this->USERID . '</UserID>
                <CompanyCd>' . $this->COMPANYCD . '</CompanyCd>
                <SenderID>' . $this->SENDERID . '</SenderID>
                <Password>' . $this->PASSWORD . '</Password>
              </Credential>
              <ShipViaCd>FED</ShipViaCd>
              <PODate>' . date('m/d/Y') . '</PODate>
              <RequiredDate>' . date('m/d/Y') . '</RequiredDate>
              <Payment>
                <Terms>Credit Card</Terms>
              </Payment>
              <BillTo>
                <Address>
                  <Name>' . $this->ORDER['b_name'] . '</Name>
                  <Addr1>' . $this->ORDER['b_street'] . '</Addr1>
                  <Addr2>' . $this->ORDER['b_street2'] . '</Addr2>
                  <City>' . $this->ORDER['b_city'] . '</City>
                  <State>' . $this->ORDER['b_state'] . '</State>
                  <Zip>' . $this->ORDER['b_zip'] . '</Zip>
                  <Attn>' . $this->ORDER['b_name'] . '</Attn>
                </Address>
                <EmailFlag>1</EmailFlag>
                <EmailAddr>' . $this->ORDER['b_email'] . '</EmailAddr>
                <PONumber>' . $this->ORDER['final_id'] . '-' . $this->ORDER['id'] . '</PONumber>
                <Id>' . $this->ORDER['final_id'] . '-' . $this->ORDER['id'] . '</Id>
              </BillTo>
              <ShipTo>
                <Address>
                  <Name>' . $this->ORDER['s_name'] . '</Name>
                  <Addr1>' . $this->ORDER['s_street'] . '</Addr1>
                  <Addr2>' . $this->ORDER['s_street2'] . '</Addr2>
                  <City>' . $this->ORDER['s_city'] . '</City>
                  <State>' . $this->ORDER['s_state'] . '</State>
                  <Zip>' . $this->ORDER['s_zip'] . '</Zip>
                  <Attn>' . $this->ORDER['s_name'] . '</Attn>
                </Address>
                <EmailFlag>0</EmailFlag>
                <EmailAddr>' . $this->ORDER['s_email'] . '</EmailAddr>
                <PONumber>' . $this->ORDER['final_id'] . '-' . $this->ORDER['id'] . '</PONumber>
                <Id>' . $this->ORDER['final_id'] . '-' . $this->ORDER['id'] . '</Id>
              </ShipTo>
              <Customer>
                <CustomerPO>' . $this->ORDER['final_id'] . '-' . $this->ORDER['id'] . '</CustomerPO>
              </Customer>
              <ItemList>' . PHP_EOL;

        foreach ($this->ORDER['items'] as $itemId => $value) {
            $xml .= '<Item>
                            <SKU>' . $value['skew'] . '</SKU>
                            <ShortDesc>' . $value['title'] . '</ShortDesc>
                            <Qty>' . $value['quantity'] . '</Qty>
                            <Price>' . $value['price'] . '</Price>
                            <Uom>EA</Uom>
                            <MfgItemNo></MfgItemNo>
                            <CusItemNo>' . $value['product_id'] . '</CusItemNo>
                            <POLineNo></POLineNo>
                     </Item>' . PHP_EOL;
        }
        $xml .= '</ItemList>
                <Notes>NONE</Notes>
            </OrderSubmit>';

        $this->XML = $xml;
        //Format XML for pretty output
        $this->PRETTYXML = nl2br(htmlentities($this->XML));
        $this->process();
    }

    public function process() {

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->POSTURL);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $this->XML);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_VERBOSE, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, Array("Content-Type: text/xml"));

        $response = curl_exec($ch);
        $results = new SimpleXmlElement($response);

        if (empty($results->OrderReference->RefNumber)) {
            $this->error($results->OrderReference);
        } else {
            $this->success($results->OrderReference);
        }
    }

    public function error($error) {
        //Set Order row in database to #4 which is Error
        $mysql = db();
        $mysql->query('UPDATE .orders SET `status`="4", `xml` = ' . $this->smartquote($this->XML) . ', `westpoint_response` = ' . $this->smartquote($error->asXML()) . ' WHERE id=' . $this->smartquote($this->ORDERID));

        $subject = 'Error on Processing WestPoint Products Order';
        $body = '<h1>There was an error sending an order to WestPoint Products</h1>
            <p>The following is the order details that we have stored for this order.</p>
                <ul>
                    <li><strong>ORDER NUMBER:</strong> ' . $this->ORDERID . ' </li>
                    <li><strong>TIMESTAMP:</strong> ' . date("Y-m-d H:i:s") . '</li>
                    <li><strong>ERROR:</strong> ' . $error->ItemList->Error->Type . '</li>
                    <li><strong>ERROR:</strong> ' . $error->ItemList->Error->Description . '</li>
                    <li><strong>XML Submitted:</strong></li> 
                </ul>
                
                <code>' . $this->PRETTYXML . '</code>';

        $headers = 'MIME-Version: 1.0' . "\r\n";
        $headers .= 'Content-type: text/html; charset=iso-8859-1' . "\r\n";
        $headers .= 'From: www@example.com' . "\r\n";
        $headers .= 'Reply-To: www@example.com' . "\r\n";
        $headers .= 'X-Mailer: PHP/' . phpversion();

        mail($this->SUPPORTEMAIL, $subject, $body, $headers);
    }

    public function success($success) {
        //Set Order row in database to #1 which is Submitted
        $mysql = db();
        $mysql->query('UPDATE .orders SET `status`="1", `xml` = ' . $this->smartquote($this->XML) . ', `westpoint_response` = ' . $this->smartquote($success->asXML()) . ' WHERE id=' . $this->smartquote($this->ORDERID));
    }

    
    /**
     * Wrap values in quotes if a string is passed, check numeric values
     * @param mixed $value Value to encapsulate
     * @param int $valType Force variable type
     * @return string Safe value to pass into a SQL query
     */    
    
    public function smartquote($value, $valType = "") {

        if (empty($value) && !is_numeric($value)) {
            return "NULL";
        } elseif (empty($value) && is_numeric($value)) {
            return 0;
        } else {
            if (get_magic_quotes_gpc()) {
                $value = stripslashes($value);
            }
            if (!is_numeric($value)) {
                if ((empty($valType)) || ($valType == 'MYSQL_STRING'))
                    $value = "'" . db()->real_escape_string(trim(htmlspecialchars_decode($value, ENT_QUOTES))) . "'";
            } else {
                if ($valType == 'MYSQL_STRING')
                    $value = "'" . db()->real_escape_string(trim(htmlspecialchars_decode($value, ENT_QUOTES))) . "'";
            }
            return $value;
        }
    }    
    
    
}
