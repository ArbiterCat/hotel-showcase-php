<?php

declare(strict_types=1);

namespace Hotels;

use PDO;
use PDOStatement;

final class Catalog
{
    private PDO $db;
    private string $active = 'supplier_a';
    private string $staging = 'supplier_b';
    private PDOStatement $contractWrite;
    private PDOStatement $supplierWrite;
    private ?PDOStatement $importWrite = null;
    private int $version = 0;

    public function __construct()
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db->exec('PRAGMA temp_store = MEMORY');
        $this->createTable('contracts');
        $this->createTable($this->active);
        $this->db->exec('CREATE TABLE hidden (id TEXT PRIMARY KEY) WITHOUT ROWID');
        $this->contractWrite = $this->prepareWrite('contracts');
        $this->supplierWrite = $this->prepareWrite($this->active);
    }

    public function get(string $id): ?array
    {
        $table = Hotel::contractId($id) ? 'contracts' : $this->active;
        $query = $this->db->prepare("SELECT * FROM $table WHERE id = ?");
        $query->execute([$id]);
        return $query->fetch() ?: null;
    }

    public function isHidden(string $id): bool
    {
        $query = $this->db->prepare('SELECT 1 FROM hidden WHERE id = ?');
        $query->execute([$id]);
        return (bool) $query->fetchColumn();
    }

    public function putContract(array $hotel): void
    {
        $this->write($this->contractWrite, $hotel, 0.0);
    }

    public function putQuote(array $hotel, float $freshAt): void
    {
        $this->write($this->supplierWrite, $hotel, $freshAt);
    }

    public function removeSupplier(string $id): void
    {
        $this->db->prepare("DELETE FROM {$this->active} WHERE id = ?")->execute([$id]);
    }

    public function delete(string $id): bool
    {
        $contract = Hotel::contractId($id);
        $table = $contract ? 'contracts' : $this->active;
        $delete = $this->db->prepare("DELETE FROM $table WHERE id = ?");
        $delete->execute([$id]);
        if ($delete->rowCount() === 0) {
            return false;
        }
        if (!$contract) {
            $this->db->prepare('INSERT INTO hidden VALUES (?)')->execute([$id]);
        }
        return true;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function beginImport(): void
    {
        $this->createTable($this->staging);
        $this->importWrite = $this->prepareWrite($this->staging);
    }

    public function stageBatch(iterable $hotels, float $receivedAt): void
    {
        $this->db->beginTransaction();
        try {
            foreach ($hotels as $hotel) {
                $this->write($this->importWrite, $hotel, $receivedAt);
            }
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    public function publishImport(): array
    {
        $imported = (int) $this->db->query("SELECT COUNT(*) FROM {$this->staging}")->fetchColumn();
        $dropped = (int) $this->db->query(
            "SELECT COUNT(*) FROM {$this->active} AS old
             WHERE NOT EXISTS (SELECT 1 FROM {$this->staging} AS incoming WHERE incoming.id = old.id)"
        )->fetchColumn();

        $this->db->exec("DELETE FROM hidden WHERE id IN (SELECT id FROM {$this->staging})");
        $this->supplierWrite = $this->importWrite;
        $this->importWrite = null;
        $previous = $this->active;
        $this->active = $this->staging;
        $this->staging = $previous;
        ++$this->version;
        $this->db->exec("DROP TABLE $previous");

        return ['imported' => $imported, 'dropped' => $dropped];
    }

    public function discardImport(): void
    {
        $this->importWrite = null;
        $this->db->exec("DROP TABLE IF EXISTS {$this->staging}");
    }

    public function count(): int
    {
        return (int) $this->db->query(
            "SELECT (SELECT COUNT(*) FROM contracts) + (SELECT COUNT(*) FROM {$this->active})"
        )->fetchColumn();
    }

    public function search(string $city, int $maxPrice, int $stars, int $limit, int $offset): string
    {
        $where = 'WHERE city = :city AND price_minor <= :price AND COALESCE(stars, 0) >= :stars';
        $params = ['city' => $city, 'price' => $maxPrice, 'stars' => $stars];

        $count = $this->db->prepare(
            "SELECT (SELECT COUNT(*) FROM {$this->active} $where) + (SELECT COUNT(*) FROM contracts $where)"
        );
        $this->executeSearch($count, $params);
        $total = (int) $count->fetchColumn();

        $items = [];
        if ($offset < $total) {
            $query = $this->db->prepare(
                "SELECT * FROM {$this->active} $where
                 UNION ALL SELECT * FROM contracts $where
                 ORDER BY price_minor ASC, id ASC LIMIT :limit OFFSET :offset"
            );
            $this->executeSearch($query, $params + ['limit' => $limit, 'offset' => $offset]);
            while ($row = $query->fetch()) {
                $items[] = Hotel::json($row);
            }
        }
        return '{"total":' . $total . ',"items":[' . implode(',', $items) . ']}';
    }

    private function createTable(string $table): void
    {
        $this->db->exec(
            "CREATE TABLE $table (
                id TEXT PRIMARY KEY, name TEXT NOT NULL, city TEXT NOT NULL, price_minor INTEGER NOT NULL,
                stars INTEGER, address TEXT, amenities TEXT NOT NULL, fresh_at REAL NOT NULL
            ) WITHOUT ROWID;
            CREATE INDEX {$table}_search ON $table (city, price_minor, id, stars)"
        );
    }

    private function prepareWrite(string $table): PDOStatement
    {
        return $this->db->prepare(
            "INSERT OR REPLACE INTO $table
             (id, name, city, price_minor, stars, address, amenities, fresh_at)
             VALUES (:id, :name, :city, :price_minor, :stars, :address, :amenities, :fresh_at)"
        );
    }

    private function write(PDOStatement $statement, array $hotel, float $freshAt): void
    {
        $statement->execute([
            'id' => $hotel['id'], 'name' => $hotel['name'], 'city' => $hotel['city'],
            'price_minor' => $hotel['price_minor'], 'stars' => $hotel['stars'], 'address' => $hotel['address'],
            'amenities' => $hotel['amenities'], 'fresh_at' => $freshAt,
        ]);
    }

    private function executeSearch(PDOStatement $statement, array $params): void
    {
        foreach ($params as $key => $value) {
            $statement->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->execute();
    }
}
