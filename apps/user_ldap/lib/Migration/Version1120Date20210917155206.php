<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\User_LDAP\Migration;

use Closure;
use OC\Hooks\PublicEmitter;
use OCP\DB\Exception;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Psr\Log\LoggerInterface;

class Version1120Date20210917155206 extends SimpleMigrationStep {

	public function __construct(
		private IDBConnection $dbc,
		private IUserManager $userManager,
		private LoggerInterface $logger,
	) {
	}

	public function getName() {
		return 'Adjust LDAP user and group id column lengths to match server lengths';
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 */
	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		// ensure that there is no user or group id longer than 64char in LDAP table
		$this->handleIDs('ldap_group_mapping', false);
		$this->handleIDs('ldap_user_mapping', true);
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$changeSchema = false;
		foreach (['ldap_user_mapping', 'ldap_group_mapping'] as $tableName) {
			$table = $schema->getTable($tableName);
			$column = $table->getColumn('owncloud_name');
			if ($column->getLength() > 64) {
				$column->setLength(64);
				$changeSchema = true;
			}
		}

		return $changeSchema ? $schema : null;
	}

	protected function handleIDs(string $table, bool $emitHooks) {
		$select = $this->getSelectQuery($table);
		$update = $this->getUpdateQuery($table);

		$result = $select->executeQuery();
		while ($row = $result->fetch()) {
			$newId = hash('sha256', $row['owncloud_name'], false);
			if ($emitHooks) {
				$this->emitUnassign($row['owncloud_name'], true);
			}
			$update->setParameter('uuid', $row['directory_uuid']);
			$update->setParameter('newId', $newId);
			try {
				$update->executeStatement();
				if ($emitHooks) {
					$this->emitUnassign($row['owncloud_name'], false);
					$this->emitAssign($newId);
				}
			} catch (Exception $e) {
				$this->logger->error('Failed to shorten owncloud_name "{oldId}" to "{newId}" (UUID: "{uuid}" of {table})',
					[
						'app' => 'user_ldap',
						'oldId' => $row['owncloud_name'],
						'newId' => $newId,
						'uuid' => $row['directory_uuid'],
						'table' => $table,
						'exception' => $e,
					]
				);
			}
		}
		$result->closeCursor();
	}

	protected function getSelectQuery(string $table): IQueryBuilder {
		$qb = $this->dbc->getQueryBuilder();
		$qb->select('owncloud_name', 'directory_uuid')
			->from($table)
			->where($qb->expr()->gt($qb->func()->octetLength('owncloud_name'), $qb->createNamedParameter('64'), IQueryBuilder::PARAM_INT));
		return $qb;
	}

	protected function getUpdateQuery(string $table): IQueryBuilder {
		$qb = $this->dbc->getQueryBuilder();
		$qb->update($table)
			->set('owncloud_name', $qb->createParameter('newId'))
			->where($qb->expr()->eq('directory_uuid', $qb->createParameter('uuid')));
		return $qb;
	}

	protected function emitUnassign(string $oldId, bool $pre): void {
		if ($this->userManager instanceof PublicEmitter) {
			$this->userManager->emit('\OC\User', $pre ? 'pre' : 'post' . 'UnassignedUserId', [$oldId]);
		}
	}

	protected function emitAssign(string $newId): void {
		if ($this->userManager instanceof PublicEmitter) {
			$this->userManager->emit('\OC\User', 'assignedUserId', [$newId]);
		}
	}
}
