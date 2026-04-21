<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateInstitutionBlocs extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('institution_blocs', [
            'id' => false,
            'primary_key' => ['id'],
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);

        $table
            ->addColumn('id', 'integer', ['identity' => true])
            ->addColumn('section_key', 'string', ['limit' => 80, 'null' => false])
            ->addColumn('section_label', 'string', ['limit' => 120, 'null' => false])
            ->addColumn('bloc_type', 'enum', [
                'values' => ['texte','image','galerie','stats','citation','colonnes','separateur'],
                'default' => 'texte',
                'null' => false,
            ])
            ->addColumn('contenu', 'text', ['limit' => 16777215, 'null' => true])
            ->addColumn('image_path', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('config_json', 'text', ['null' => true])
            ->addColumn('ordre', 'smallinteger', ['default' => 0, 'null' => false])
            ->addColumn('visible', 'boolean', ['default' => 1, 'null' => false])
            ->addColumn('updated_at', 'timestamp', [
                'default' => 'CURRENT_TIMESTAMP',
                'update' => 'CURRENT_TIMESTAMP',
                'null' => false,
            ])
            ->create();
    }
}


