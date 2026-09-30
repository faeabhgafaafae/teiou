<?php
/**
 * テスト専用: 本番コードのMySQL方言をSQLiteで実行できるよう読み替えるPDO。
 */
final class MysqlCompatPdo extends PDO
{
    private const CONFLICT_KEYS = [
        'predictions'    => 'race_id, player_id',
        'strategies'     => 'race_id, strategy_type',
        'predictions_v2' => 'race_id, player_id',
    ];

    #[\ReturnTypeWillChange]
    public function prepare($query, $options = [])
    {
        return parent::prepare($this->translate($query), $options);
    }

    #[\ReturnTypeWillChange]
    public function exec($statement)
    {
        try {
            return parent::exec($this->translate($statement));
        } catch (PDOException $e) {
            if (stripos($e->getMessage(), 'duplicate column name') !== false) {
                // MySQL の ER_DUP_FIELDNAME(1060) と同じ形で投げ直す
                $dup = new PDOException($e->getMessage());
                $dup->errorInfo = ['42S21', 1060, $e->getMessage()];
                throw $dup;
            }
            throw $e;
        }
    }

    private function translate(string $sql): string
    {
        if (preg_match('/INSERT\s+INTO\s+(\w+)/i', $sql, $m) && isset(self::CONFLICT_KEYS[$m[1]])) {
            $sql = preg_replace('/ON\s+DUPLICATE\s+KEY\s+UPDATE/i',
                'ON CONFLICT(' . self::CONFLICT_KEYS[$m[1]] . ') DO UPDATE SET', $sql);
            $sql = preg_replace('/VALUES\((\w+)\)/i', 'excluded.$1', $sql);
        }
        return str_ireplace('NOW()', 'CURRENT_TIMESTAMP', $sql);
    }
}
