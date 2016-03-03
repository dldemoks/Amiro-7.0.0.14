<?php

chdir(realpath('../../'));
require_once 'ami_env.php';

require_once '_local/eshop/AtoPaymentSystem.php';

class Payeer_Callback
{
    public function __construct(array $request)
    {
        $this->_secretKey = (string)AtoPaymentSystem::getDriverParameter('payeer', 'payeer_secret_key');
		$this->_ipfilter = (string) AtoPaymentSystem::getDriverParameter('payeer', 'payeer_ip_filter');
		$this->_log = (string)AtoPaymentSystem::getDriverParameter('payeer', 'payeer_log');
		$this->_emailerr = (string)AtoPaymentSystem::getDriverParameter('payeer', 'payeer_email_error');
		$this->_request = $request;
		echo $this->validateRequestParams();
    }

    private function validateRequestParams()
    {
		if (isset($this->_request['m_operation_id']) && isset($this->_request['m_sign']))
		{
			// запись логов
			
			$log_text = 
				"--------------------------------------------------------\n" .
				"operation id		" . $this->_request["m_operation_id"] . "\n" .
				"operation ps		" . $this->_request["m_operation_ps"] . "\n" .
				"operation date		" . $this->_request["m_operation_date"] . "\n" .
				"operation pay date	" . $this->_request["m_operation_pay_date"] . "\n" .
				"shop				" . $this->_request["m_shop"] . "\n" .
				"order id			" . $this->_request["m_orderid"] . "\n" .
				"amount				" . $this->_request["m_amount"] . "\n" .
				"currency			" . $this->_request["m_curr"] . "\n" .
				"description		" . base64_decode($this->_request["m_desc"]) . "\n" .
				"status				" . $this->_request["m_status"] . "\n" .
				"sign				" . $this->_request["m_sign"] . "\n\n";
				
			if (!empty($this->_log))
			{
				file_put_contents($_SERVER['DOCUMENT_ROOT'] . $this->_log, $log_text, FILE_APPEND);
			}
			
			
			// вычисление цифровой подписи
			
			$sign_hash = strtoupper(hash('sha256', implode(":", array(
				$this->_request['m_operation_id'],
				$this->_request['m_operation_ps'],
				$this->_request['m_operation_date'],
				$this->_request['m_operation_pay_date'],
				$this->_request['m_shop'],
				$this->_request['m_orderid'],
				$this->_request['m_amount'],
				$this->_request['m_curr'],
				$this->_request['m_desc'],
				$this->_request['m_status'],
				$this->_secretKey
			))));
			
			
			// подлинность ip адреса
			
			$valid_ip = true;
			$list_ip_str = str_replace(' ', '', $this->_ipfilter);
			
			if (!empty($list_ip_str)) 
			{
				$list_ip = explode(',', $list_ip_str);
				$this_ip_field = explode('.', $_SERVER['REMOTE_ADDR']);
				$list_ip_field = array();
				$i = 0;
				$valid_ip = false;
				foreach ($list_ip as $ip)
				{
					$ip_field[$i] = explode('.', $ip);
					if ((($this_ip_field[0] ==  $ip_field[$i][0]) || ($ip_field[$i][0] == '*')) &&
						(($this_ip_field[1] ==  $ip_field[$i][1]) || ($ip_field[$i][1] == '*')) &&
						(($this_ip_field[2] ==  $ip_field[$i][2]) || ($ip_field[$i][2] == '*')) &&
						(($this_ip_field[3] ==  $ip_field[$i][3]) || ($ip_field[$i][3] == '*')))
					{
						$valid_ip = true;
						break;
					}
					$i++;
				}
			}
			
			
			// проверка цифровой подписи и ip
		
			if (!($this->_request["m_sign"] == $sign_hash && $valid_ip))
			{
				if (!empty($this->_emailerr))
				{
					$message = "Failed to make the payment through Payeer for the following reasons:\n\n";
					
					if (!$valid_ip)
					{
						$message .= " - ip address of the server is not trusted\n" . 
									"   trusted ip: " . $this->_ipfilter . "\n" . 
									"   ip of the current server: " . $_SERVER['REMOTE_ADDR'] . "\n";
					}
					
					if ($this->_request["m_sign"] != $sign_hash)
					{
						$message .= " - Do not match the digital signature\n";
					}
					
					$message .= "\n" . $log_text;
					$headers = "From: no-reply@" . $_SERVER['HTTP_SERVER'] . "\r\n" . 
								"Content-type: text/plain; charset=utf-8 \r\n";
						
					mail($this->_emailerr, 'Payment error', $message, $headers);
				}

				return $this->_request['m_orderid'] . '|error';
			}
			
			
			// загрузка заказа
			
			$oDB = AMI::getSingleton('db');

			$order = $oDB->fetchRow(
				DB_Query::getSnippet("SELECT `status`,`sysinfo`,`total` FROM `cms_es_orders` WHERE `id` = %s")
				->q($this->_request['m_orderid'])
			);
			
			$order_curr = preg_replace('/^.*s:8:"fee_curr";s:.+?:"(.+?)".*$/', '$1', $order['sysinfo']);
			$order_curr = ($order_curr == 'RUR') ? 'RUB' : $order_curr;
			$order_amount = number_format($order['total'], 2, '.', '');
			
			
			// проверка суммы и валюты
			
			if (!($this->_request['m_amount'] == $order_amount && $this->_request['m_curr'] == $order_curr))
			{
				if (!empty($this->_emailerr))
				{
					$message = "Failed to make the payment through Payeer for the following reasons:\n\n";
					
					if ($this->_request['m_amount'] != $order_amount)
					{
						$message .= " - Wrong amount\n";
					}
					
					if ($this->_request['m_curr'] != $order_curr)
					{
						$message .= " - Wrong currency\n";
					}
					
					$message .= "\n" . $log_text;
					$headers = 
						"From: no-reply@" . $_SERVER['HTTP_SERVER'] . "\r\n" . 
						"Content-type: text/plain; charset=utf-8 \r\n";
						
					mail($this->_emailerr, 'Payment error', $message, $headers);
				}

				return $this->_request['m_orderid'] . '|error';
			}
			
			
			// проверка статуса
			
			switch ($this->_request['m_status'])
			{
				case 'success':
					if ($order['status'] != 'checkout')
					{
						$qupdate = $oDB->fetchValue(DB_Query::getUpdateQuery(
							'cms_es_orders',
							array('status'  => 'confirmed_done'),
							DB_Query::getSnippet('WHERE id IN (%s)')->q($this->_request['m_orderid'])
						));
						
						return $this->_request['m_orderid'] . '|success';
					}
					break;
					
				case 'fail':
					if ($order['status'] != 'checkout')
					{
						$qupdate = $oDB->fetchValue(DB_Query::getUpdateQuery(
							'cms_es_orders',
							array('status'  => 'cancelled'),
							DB_Query::getSnippet('WHERE id IN (%s)')->q($this->_request['m_orderid'])
						));
						
						if (!empty($this->_emailerr))
						{
							$to = $this->_emailerr;
							$subject = "Payment error";
							$message = "Failed to make the payment through Payeer for the following reasons:\n\n";
							$message .= " - The payment status is not success\n";
							$message .= "\n" . $log_text;
							$headers = "From: no-reply@" . $_SERVER['HTTP_SERVER'] . "\r\nContent-type: text/plain; charset=utf-8 \r\n";
							mail($to, $subject, $message, $headers);
						}
					
						return $this->_request['m_orderid'] . '|error';
					}
					break;
					
				default: 
					return $this->_request['m_orderid'] . '|error';
					break;
			}
		}
		else
		{
			return false;
		}
    }
}

$atoPayeerCallback = new Payeer_Callback($_POST);