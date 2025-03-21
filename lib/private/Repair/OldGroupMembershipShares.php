<?php

/**
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OC\Repair;

use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use OCP\Share\IShare;

class OldGroupMembershipShares implements IRepairStep {
	/**
	 * @var array [gid => [uid => (bool)]]
	 */
	protected $memberships;

	/**
	 * @param IDBConnection $connection
	 * @param IGroupManager $groupManager
	 */
	public function __construct(
		protected IDBConnection $connection,
		protected IGroupManager $groupManager,
	) {
	}

	/**
	 * Returns the step's name
	 *
	 * @return string
	 */
	public function getName() {
		return 'Remove shares of old group memberships';
	}

	/**
	 * Run repair step.
	 * Must throw exception on error.
	 *
	 * @throws \Exception in case of failure
	 */
	public function run(IOutput $output) {
		$deletedEntries = 0;

		$query = $this->connection->getQueryBuilder();
		$query->select('s1.id')->selectAlias('s1.share_with', 'user')->selectAlias('s2.share_with', 'group')
			->from('share', 's1')
			->where($query->expr()->isNotNull('s1.parent'))
				// \OC\Share\Constant::$shareTypeGroupUserUnique === 2
			->andWhere($query->expr()->eq('s1.share_type', $query->expr()->literal(2)))
			->andWhere($query->expr()->isNotNull('s2.id'))
			->andWhere($query->expr()->eq('s2.share_type', $query->expr()->literal(IShare::TYPE_GROUP)))
			->leftJoin('s1', 'share', 's2', $query->expr()->eq('s1.parent', 's2.id'));

		$deleteQuery = $this->connection->getQueryBuilder();
		$deleteQuery->delete('share')
			->where($query->expr()->eq('id', $deleteQuery->createParameter('share')));

		$result = $query->executeQuery();
		while ($row = $result->fetch()) {
			if (!$this->isMember($row['group'], $row['user'])) {
				$deletedEntries += $deleteQuery->setParameter('share', (int)$row['id'])
					->executeStatement();
			}
		}
		$result->closeCursor();

		if ($deletedEntries) {
			$output->info('Removed ' . $deletedEntries . ' shares where user is not a member of the group anymore');
		}
	}

	/**
	 * @param string $gid
	 * @param string $uid
	 * @return bool
	 */
	protected function isMember($gid, $uid) {
		if (isset($this->memberships[$gid][$uid])) {
			return $this->memberships[$gid][$uid];
		}

		$isMember = $this->groupManager->isInGroup($uid, $gid);
		if (!isset($this->memberships[$gid])) {
			$this->memberships[$gid] = [];
		}
		$this->memberships[$gid][$uid] = $isMember;

		return $isMember;
	}
}
