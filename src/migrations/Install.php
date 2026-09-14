<?php

declare(strict_types=1);

namespace justinholtweb\glue\migrations;

use craft\db\Migration;
use craft\db\Table as CraftTable;
use justinholtweb\glue\db\Table;

/**
 * Creates the merge history.
 *
 * ## Why the titles are copied into the row
 *
 * The whole point of the history is to be readable *after* the sources are gone — somebody asks
 * in March what happened to the old press release, and the answer has to survive the trash being
 * emptied in February. So the row carries the titles it saw, denormalised, and the IDs are kept
 * beside them for as long as they resolve.
 *
 * ## Why there are no foreign keys to `elements`
 *
 * A cascade on `targetId` would delete the record of a merge whenever its result was deleted,
 * which is precisely the case somebody is trying to investigate. `SET NULL` would keep the row
 * and lose the ID. Neither is what an audit trail wants, so the IDs are plain integers and the
 * pruning is done deliberately by garbage collection against `historyLimit`.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->tableExists(Table::MERGES)) {
            return true;
        }

        $this->createTable(Table::MERGES, [
            'id' => $this->primaryKey(),
            'targetId' => $this->integer(),
            'aId' => $this->integer(),
            'bId' => $this->integer(),
            'siteId' => $this->integer(),
            'userId' => $this->integer(),
            'targetTitle' => $this->string(255),
            'aTitle' => $this->string(255),
            'bTitle' => $this->string(255),
            'target' => $this->string(16)->notNull()->defaultValue('new'),
            'disposition' => $this->string(16)->notNull()->defaultValue('leave'),
            'createdNew' => $this->boolean()->notNull()->defaultValue(true),
            'rewired' => $this->integer()->notNull()->defaultValue(0),
            'rewireQueued' => $this->boolean()->notNull()->defaultValue(false),
            'preset' => $this->string(255),
            'plan' => $this->text(),
            'warnings' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, Table::MERGES, ['dateCreated'], false);
        $this->createIndex(null, Table::MERGES, ['targetId'], false);
        $this->createIndex(null, Table::MERGES, ['aId'], false);
        $this->createIndex(null, Table::MERGES, ['bId'], false);

        // The site may be deleted; the merge still happened. Only the user gets a key, because a
        // deleted user is a `SET NULL` and the row reads "by somebody who is gone", which is
        // true and useful, rather than disappearing.
        $this->addForeignKey(null, Table::MERGES, ['userId'], CraftTable::USERS, ['id'], 'SET NULL', null);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::MERGES);

        return true;
    }
}
