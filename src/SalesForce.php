<?php
/**
 * Created by PhpStorm.
 * User: panarican
 * Date: 2/4/18
 * Time: 3:17 PM
 */

namespace Panarican\SalesForce;

use SalesforceRestAPI\SalesforceAPI;

abstract class SalesForce implements SalesForceInterface {
	/**
	 * SalesForce Object Name
	 *
	 * @var $objectName
	 */
	public $objectName = '';

	/**
	 * List of Payload Fields
	 *
	 * @var $payloadFields
	 */
	public $payloadFields = [];

	/**
	 * List of Reference Fields
	 *
	 * @var $referenceFields
	 */
	public $referenceFields = [];

	/**
	 * Client is based on SalesforceAPI that's set in the constructor
	 *
	 * @var $client
	 */
	public $client;

	private $settings = [];

	/**
	 * SalesForce constructor.
	 *
	 * @param array $settings
	 *
	 * @throws \SalesforceRestAPI\SalesforceAPIException
	 */
	public function __construct($settings = []) {
		$this->settings = $settings;
		$instance = $this->getSetting('instance');
		$id = $this->getSetting('id');
		$secret = $this->getSetting('secret');
		$username = $this->getSetting('username');
		$password = $this->getSetting('password');
		$token = $this->getSetting('token');
		$url = empty($instance) ? NULL : "https://{$instance}.salesforce.com";
		$version = '41.0';

		$this->client = new SalesforceAPI(
			$url,
			$version,
			$id,
			$secret
		);
		$this->client->login(
			$username,
			$password,
			$token
		);
	}

	/**
	 * @param $option
	 *
	 * @return mixed|void
	 */
	private function getSetting($option) {
		$setting = isset($this->settings[$option]) ? $this->settings[$option] : NULL;
		$wpOption = function_exists('get_option') ? get_option($option) : NULL;
		return $setting ? $setting : $wpOption;
	}


	/**
	 * @param $value
	 * @param bool $sql
	 *
	 * @return string
	 */
	private function sanitizeValue($value, $sql = false) {
		$value = stripslashes($value);
		$value = htmlentities($value);
		$value = strip_tags($value);
		$value = trim($value);
		if ($sql) {
			$value = str_replace('"', '', $value);
			$value = str_replace("'", "\'", $value);
			//$value = '"' . $value . '"';
		}
		return $value;
	}

	/**
	 * @param array $payload
	 *
	 * @return array
	 */
	public function buildPayload( array $payload ):array {
		$fields = [];
		foreach($this->payloadFields as $item) {
			$value = isset($payload[$item]) ? $payload[$item] : NULL;
			if (isset($value)) {
				$fields[$item] = $value;
			}
		}
		return $fields;
	}

	/**
	 * @param array $payload
	 *
	 * @return mixed
	 * @throws \SalesforceRestAPI\SalesforceAPIException
	 */
	public function create( array $payload ) {
		if (empty($payload)) {
			return false;
		}
		$fields = $this->buildPayload($payload);
		return $this->client->create($this->objectName, $fields);
	}

	/**
	 * @param string $objectId
	 *
	 * @return mixed
	 * @throws \SalesforceRestAPI\SalesforceAPIException
	 */
	public function get( string $objectId ) {
		if (empty($objectId)) {
			return false;
		}
		return $this->client->get($this->objectName, $objectId, $this->payloadFields);
	}

	/**
	 * @param string $objectId
	 * @param array $payload
	 *
	 * @return mixed
	 * @throws \SalesforceRestAPI\SalesforceAPIException
	 */
	public function update( string $objectId, array $payload ) {
		if (empty($objectId) || empty($payload)) {
			return false;
		}
		$fields = $this->buildPayload($payload);
		return $this->client->update($this->objectName, $objectId, $fields);
	}

	/**
	 * @param string $objectId
	 *
	 * @return mixed
	 * @throws \SalesforceRestAPI\SalesforceAPIException
	 */
	public function delete( string $objectId ) {
		if (empty($objectId)) {
			return false;
		}
		return $this->client->delete($this->objectName, $objectId);
	}

	/**
	 * @param array $conditions
	 * @param $limit
	 *
	 * @return mixed
	 * @throws \SalesforceRestAPI\SalesforceAPIException
	 */
	public function query( array $conditions, $limit = 0 ) {
		$columns = $this->payloadFields;
		$columns[] = "Id";
		$selectColumns = array_merge($columns, $this->referenceFields);
		$selectColumnsList = implode(', ', $selectColumns);
		$objectName = $this->objectName;
		$select = "SELECT {$selectColumnsList}\n";
		$from = " FROM {$objectName}\n";
		$where = empty($conditions) ? "" : "WHERE ";
		$index = 0;
		foreach($conditions as $key => $value) {
			$sanitizedValue = $this->sanitizeValue($value, true);
			$where .= ($index === 0) ? "{$key} = '{$sanitizedValue}'\n" : " AND {$key} = '{$sanitizedValue}'\n";
			$index++;
		}
		$limit = empty($limit) ? "" : "LIMIT {$limit}";
		$query = $select . $from . $where . $limit;
		$response = $this->client->searchSOQL($query);
		return $response;
	}

	/**
	 * @param array $conditions
	 * @param array $payload
	 *
	 * @return mixed|void
	 * @throws \SalesforceRestAPI\SalesforceAPIException
	 */
	public function queryCreateUpdate( array $conditions, array $payload ) {
		if (empty($conditions) || empty($payload)) {
			return false;
		}
		$response = $this->query($conditions, 1);
		$record = isset($response['records']) ? reset($response['records']) : NULL;
		if (empty($record)) {
			return $this->create($payload);
		} else {
			return $this->update($record['Id'], $payload);
		}
	}

	/**
	 * @return array
	 * @throws \SalesforceRestAPI\SalesforceAPIException
	 */
	public function fields():array {
		$response = $this->client->getObjectMetadata($this->objectName, true);
		$fields = is_array($response['fields']) ? $response['fields'] : [];
		return array_map(function($value) {
			return [
				'label' => $value['label'],
				'name' => $value['name'],
				'type' => $value['type'],
			];
		}, $fields);
	}
}