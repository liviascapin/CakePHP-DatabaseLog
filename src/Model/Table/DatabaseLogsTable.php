<?php
/**
 * CakePHP DatabaseLog Plugin
 *
 * Licensed under The MIT License.
 *
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 * @link https://github.com/dereuromark/CakePHP-DatabaseLog
 */
namespace DatabaseLog\Model\Table;

use ArrayObject;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\Event\Event;
use Cake\Utility\Hash;

class DatabaseLogsTable extends DatabaseLogAppTable {

	use LazyTableTrait;

	/**
	 * @var array
	 */
	public $searchFields = ['DatabaseLogs.type'];

	/**
	 * initialize method
	 *
	 * @param array $config Config data.
	 * @return void
	 */
	public function initialize(array $config) {
		$this->displayField('type');
		$this->addBehavior('Timestamp', ['modified' => false]);
		$this->ensureTables(['DatabaseLog.DatabaseLogs']);
	}

	/**
	 * Write the log to database
	 *
	 * @param mixed $level
	 * @param string $message
	 * @param array $context
	 * @return bool Success
	 */
	public function log($level, $message, array $context = []) {
		$data = [
			'type' => $level,
			'message' => is_string($message) ? $message : print_r($message, true),
			'context' => is_string($context) ? $context : print_r($context, true),
			'count' => 1
		];
		$log = $this->newEntity($data);
		return (bool)$this->save($log);
	}

	/**
	 * @param \Cake\Event\Event $event
	 * @param \Cake\Datasource\EntityInterface $entity
	 * @param \ArrayObject $options
	 * @return void
	 */
	public function beforeSave(Event $event, EntityInterface $entity, ArrayObject $options) {
		$entity['ip'] = env('REMOTE_ADDR');
		$entity['hostname'] = env('HTTP_HOST');
		$entity['uri'] = env('REQUEST_URI');
		$entity['refer'] = env('HTTP_REFERER');
		$entity['user_agent'] = env('HTTP_USER_AGENT');
	}

	/**
	* Return a text search on message
	*
	* @param string|null $query search string or `type@...`
	* @return array Conditions
	*/
	public function textSearch($query = null) {
		if ($query) {
			if (strpos($query, 'type@') === 0) {
				$query = str_replace('type@', '', $query);
				return ['Log.type' => $query];
			}

			$escapedQuery = "'" . $query . "'"; // for now - $this->getDataSource()->value($query);
			return ["MATCH ({$this->alias()}.message) AGAINST ($escapedQuery)"];
		}
		return [];
	}

	/**
	* Return all the unique types
	*
	* @return array Types
	*/
	public function getTypes() {
		$types = $this->find()->distinct($this->alias() . '.type')->select(['type'])->order($this->alias() . '.type ASC')->toArray();
		return Hash::extract($types, '{n}.type');
	}

	/**
	 * Remove duplicates and leave only the newest entry
	 * Also stores the new total "number" of this message in the remaining one
	 *
	 * @return void
	 */
	public function removeDuplicates() {
		$query = $this->find();
		$options = [
			'fields' => ['id', 'type', 'message', 'count' => $query->func()->count('*')],
			'conditions' => [],
			'group' => ['type', 'message'],
			//'having' => $this->alias . '__count > 0',
			'order' => ['created' => 'DESC']
		];
		$logs = $query->find('all', $options);
		foreach ($logs as $key => $log) {
			if ($log['count'] <= 1) {
				continue;
			}
			$options = [
				'fields' => ['id'],
				'keyField' => 'id',
				'valueField' => 'id',
				'conditions' => [
					'type' => $log['type'],
					'message' => $log['message'],
				],
				'order' => ['created' => 'DESC']
			];
			$entries = $this->find('list', $options)->toArray();

			// keep the newest entry
			$keep = array_shift($entries);
			if (!empty($entries)) {
				$this->deleteAll(['id IN' => $entries]);
			}
			$this->updateAll(['count = count + ' . count($entries)], ['id' => $keep]);
		}
	}

	/**
	 * @return int
     */
	public function garbageCollector() {
		$query = $this->find()
			->order(['id' => 'ASC']);

		$count = $query->count();

		$limit = Configure::read('DatabaseLog.limit') ?: 999999;
		if ($count <= $limit) {
			return 0;
		}

		$record = $query->where()->offset($count - $limit)->first();

		return $this->deleteAll(['id <' => $record->id]);
	}

	/**
	 * truncate()
	 *
	 * @return void
	 */
	public function truncate() {
		$sql = $this->schema()->truncateSql($this->_connection);
		foreach ($sql as $snippet) {
			$this->_connection->execute($snippet);
		}
	}

}
