<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateCodeSnippetsTable extends AbstractMigration
{
    /**
     * Change Method.
     *
     * Write your reversible migrations using this method.
     *
     * More information on writing migrations is available here:
     * https://book.cakephp.org/phinx/0/en/migrations.html#the-change-method
     *
     * Remember to call "create()" or "update()" and NOT "save()" when working
     * with the Table class.
     */
    public function change(): void
    {
        // create the table
        $table = $this->table('code_snippets', ['id' => false, 'primary_key' => ['id']]);
        $table->addColumn('id', 'uuid', ['null' => false])
              ->addColumn('title', 'string', ['limit' => 255, 'null' => false])
              ->addColumn('description', 'text', ['null' => true])
              ->addColumn('username', 'string', ['limit' => 100, 'null' => false])
              ->addColumn('language', 'string', ['limit' => 50, 'null' => false])
              ->addColumn('tags', 'text[]', ['null' => true]) // Sticking with TEXT[]
              ->addColumn('code', 'text', ['null' => false])
              ->addColumn('modification_code', 'string', ['limit' => 64, 'null' => false])
              ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'timezone' => false]) // Ensure timezone matches app logic if needed
              ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'timezone' => false]) // 'update' => 'CURRENT_TIMESTAMP' is often DB specific or handled by triggers
              ->addIndex(['language'])
              ->addIndex(['username'])
              ->addIndex(['modification_code'], ['unique' => true]) // Modification code should be unique
              ->create();
    }

    public function up(): void
    {
        $this->output->writeln('Creating code_snippets table...');
        $table = $this->table('code_snippets', ['id' => false, 'primary_key' => ['id']]);
        $table->addColumn('id', 'uuid', ['null' => false])
              ->addColumn('title', 'string', ['limit' => 255, 'null' => false])
              ->addColumn('description', 'text', ['null' => true])
              ->addColumn('username', 'string', ['limit' => 100, 'null' => false])
              ->addColumn('language', 'string', ['limit' => 50, 'null' => false])
              ->addColumn('tags', 'text[]', ['null' => true]) // Sticking with TEXT[]
              ->addColumn('code', 'text', ['null' => false])
              ->addColumn('modification_code', 'string', ['limit' => 64, 'null' => false])
              ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'timezone' => false])
              ->addColumn('updated_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP', 'timezone' => false])
              ->addIndex(['language'])
              ->addIndex(['username'])
              ->addIndex(['modification_code'], ['unique' => true])
              ->create();
        $this->output->writeln('Created code_snippets table.');
    }

    public function down(): void
    {
        $this->output->writeln('Dropping code_snippets table...');
        if ($this->hasTable('code_snippets')) {
            $this->table('code_snippets')->drop()->save();
        }
        $this->output->writeln('Dropped code_snippets table.');
    }
}
