<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000001Date20260304222000 extends SimpleMigrationStep {
	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if ($schema->hasTable('ep_pad_bindings')) {
			return $schema;
		}

		$table = $schema->createTable('ep_pad_bindings');
		$table->addColumn('id', 'integer', [
			'autoincrement' => true,
			'unsigned' => true,
			'notnull' => true,
		]);
		$table->addColumn('file_id', 'bigint', [
			'unsigned' => true,
			'notnull' => true,
		]);
		$table->addColumn('pad_id', 'string', [
			'length' => 255,
			'notnull' => true,
		]);
		$table->addColumn('access_mode', 'string', [
			'length' => 16,
			'notnull' => true,
		]);
		$table->addColumn('state', 'string', [
			'length' => 16,
			'notnull' => true,
		]);
		$table->addColumn('deleted_at', 'bigint', [
			'unsigned' => true,
			'notnull' => false,
		]);
		$table->addColumn('created_at', 'bigint', [
			'unsigned' => true,
			'notnull' => true,
		]);
		$table->addColumn('updated_at', 'bigint', [
			'unsigned' => true,
			'notnull' => true,
		]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['file_id'], 'ep_bind_file_uniq');
		$table->addUniqueIndex(['pad_id'], 'ep_bind_pad_uniq');
		$table->addIndex(['state'], 'ep_bind_state_idx');

		return $schema;
	}
}
