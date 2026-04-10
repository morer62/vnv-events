<?php

namespace App\Repositories;

use App\Services\LoginService;
use InvalidArgumentException;
use PDO;
use PDOException;

class BaseRepository
{
    private const OWNERSHIP_LEVELS = [3,4,5];
    private const OWNERSHIP_COLUMN = 'id_owner';


    /**
     * @var null|Connection
     */
    public ?Connection $db = null;

    /**
     * @var string
     */
    protected string $table = "";

    protected array $fields = [];

    /**
     * @var bool
     */
    protected bool $showError = true;

    /**
     * @param array $data
     * @return bool
     */
    

     public function add(array $data): bool
     {
         try {
             // Verificar si se debe agregar automáticamente el id_owner
             if ($this->shouldCheckOwnership() && $this->hasTableColumn(self::OWNERSHIP_COLUMN)) {
                 $session = LoginService::getSession();
                 $data[self::OWNERSHIP_COLUMN] = $session->getOwner();
             }
     
             $keys = array_keys($data);
             $insert = "(";
             $values = "(";
     
             for ($i = 0; $i < count($keys); $i++) {
                 $insert .= " `$keys[$i]`";
                 $values .= " :$keys[$i]";
                 if ($i != count($keys) - 1) {
                     $insert .= ", ";
                     $values .= ", ";
                 }
             }
     
             $insert .= ")";
             $values .= ")";
     
             $query = "INSERT INTO `$this->table` $insert VALUES $values";
     
             $this->db->query($query);
             for ($i = 0; $i < count($keys); $i++) {
                 $this->db->bind(":$keys[$i]", $data[$keys[$i]]);
             }
             $this->db->execute();
     
             return true;
         } catch (PDOException $th) {
             if ($this->showError) {
                 error_log("PDOException in BaseRepository::add(): " . $th->getMessage());
             }
     
             return false;
         }
     }
     






    /**
     * @param array $keys
     * @return bool
     */
    public function delete(Array $keys): bool
    {
        try {

            $keys2 = array_keys($keys);
            $this->db->query("DELETE FROM `$this->table` WHERE `$keys2[0]` = :data ;");
            $this->db->bind(":data",  $keys[ $keys2[0] ] );

            $this->db->execute();
            return true;
        } catch (PDOException $th) {
            if($this->showError){
                echo $th->getMessage();
            }
            return false;
        }
    }

    /**
     * @param array $data
     * @param array $criteriaVals
     * @return bool
     */
    public function update(array $data, array $criteriaVals): bool
    {

        try {
            $keys = array_keys( $data );
            $keysCriteria = array_keys($criteriaVals);
            $update = "";
            $criteria = "";

            for ($i = 0; $i < count( $keys ) ; $i++ ){
                $update .= " `$keys[$i]` = :$keys[$i] ";

                if($i != count( $keys ) -1 ){
                    $update .= ", ";
                }
            }

            for ($i = 0; $i< count( $keysCriteria ) ; $i++ ){
                $criteria .= " `$keysCriteria[$i]` = :$keysCriteria[$i] ";

                if($i != count( $keysCriteria ) -1 ){
                    $criteria .= " AND ";
                }
            }

            $query = "UPDATE `$this->table` SET $update WHERE $criteria;";

            //now we have our query we gotta bind the data to be inserted
            $this->db->query( $query );
            for($i = 0; $i < count ( $keys ) ; $i++ ){
                $this->db->bind(":$keys[$i]", $data[ $keys[$i] ] );
            }

            for($i = 0; $i < count ( $keysCriteria ) ; $i++ ){
                $this->db->bind(":$keysCriteria[$i]", $criteriaVals[ $keysCriteria[$i] ] );
            }

            $this->db->execute();

            return true;
        } catch (PDOException $th) {
            if($this->showError){
                echo $th->getMessage();
            }
            return false;
        }
    }


    /**
     * @param array $criteriaVal
     * @param array $columns
     * @return object|null
     */
    public function getOne(array $criteriaVals, array $columns = []): ?object
    {
        try {
            $columnsSQL = $this->columnsQuery($columns);
            $criteria = $criteriaVals;

            if ($this->shouldCheckOwnership() && $this->hasTableColumn(self::OWNERSHIP_COLUMN)) {
                $session = LoginService::getSession();
                $criteria[self::OWNERSHIP_COLUMN] = $session->getOwner();
            }

            $keys = array_keys($criteria);
            $where = implode(" AND ", array_map(fn($k) => "`$k` = :$k", $keys));
            $query = "SELECT $columnsSQL FROM `{$this->table}` WHERE $where";

            $this->db->query($query);
            foreach ($criteria as $key => $val) {
                $this->db->bind(":$key", $val);
            }

            $result = $this->db->fetchOne();
            return !$result ? null : $result;
        } catch (PDOException $e) {
            if ($this->showError) echo $e->getMessage();
            return null;
        }
    }


public function getFullVenueDetails(int $venueId): ?object
{
    try {
       
        $query = "
            SELECT v.*, c.name AS category_name, c.description AS category_description
            FROM venues v
            LEFT JOIN venue_categories c ON v.category_id = c.id
            WHERE v.id = :id
        ";
        $this->db->query($query);
        $this->db->bind(":id", $venueId);
        $venue = $this->db->fetchOne();

        if (!$venue) {
            return null;
        }

      
        $this->db->query("SELECT amenity FROM venue_amenities WHERE venue_id = :id");
        $this->db->bind(":id", $venueId);
        $venue->amenities = array_column($this->db->fetchAll(), 'amenity');

        $this->db->query("SELECT service FROM venue_services WHERE venue_id = :id");
        $this->db->bind(":id", $venueId);
        $venue->services = array_column($this->db->fetchAll(), 'service');

   
        $this->db->query("SELECT day, opens, closes, is_closed FROM venue_availability WHERE venue_id = :id");
        $this->db->bind(":id", $venueId);
        $venue->availability = [];
        foreach ($this->db->fetchAll() as $row) {
            $venue->availability[$row->day] = [
                'opens' => $row->opens,
                'closes' => $row->closes,
                'is_closed' => (bool)$row->is_closed
            ];
        }

        $this->db->query("SELECT image FROM venue_photos WHERE venue_id = :id");
        $this->db->bind(":id", $venueId);
        $venue->photos = array_column($this->db->fetchAll(), 'image');

        $this->db->query("SELECT id, start_date, end_date, image, name, description FROM venue_promotions WHERE venue_id = :id");
        $this->db->bind(":id", $venueId);
        $venue->promos = $this->db->fetchAll();

     
        $this->db->query("
            SELECT ve.*, vet.ticket_sales_enabled 
            FROM venue_events ve 
            LEFT JOIN venue_events_tickets vet ON ve.id = vet.id_venue_event 
            WHERE ve.venue_id = :id
        ");
        $this->db->bind(":id", $venueId);
        $venue->events = $this->db->fetchAll();

    
        $this->db->query("SELECT vc.name FROM venue_categories_assigned vca INNER JOIN venue_categories vc ON vc.id = vca.category_id WHERE vca.venue_id = :id ORDER BY vc.name ASC");
        $this->db->bind(":id", $venueId);
        $venue->extra_categories = array_map(function($r){ return is_object($r) ? $r->name : $r['name']; }, $this->db->fetchAll() ?: []);

        return $venue;
    } catch (PDOException $e) {
        if ($this->showError) echo $e->getMessage();
        return null;
    }
}


    /**
     * @param array $columns
     * @param int $limit
     * @return array
     */
    public function getAll(array $columns = [], int $limit = 0): array
    {
        try {
            $columnsSQL = $this->columnsQuery($columns);
            $limitSQL = $limit > 0 ? "LIMIT $limit" : "";
            $query = "SELECT $columnsSQL FROM `{$this->table}`";
            $params = [];

            if ($this->shouldCheckOwnership() && $this->hasTableColumn(self::OWNERSHIP_COLUMN)) {
                $session = LoginService::getSession();
                $query .= " WHERE `id_owner` = :owner_id";
                $params['owner_id'] =  $session->getOwner();
            }

            $query .= " $limitSQL";
            $this->db->query($query);

            foreach ($params as $key => $val) {
                $this->db->bind(":$key", $val);
            }

            return $this->db->fetchAll();
        } catch (PDOException $e) {
            if ($this->showError) echo $e->getMessage();
            return [];
        }
    }



    public function getAllBy(array $criteriaVals, array $columns = [], int $limit = 0): array
    {
        try {
            $columnsSQL = $this->columnsQuery($columns);
            $limitSQL = $limit > 0 ? "LIMIT $limit" : "";
            $criteria = $criteriaVals;

            if ($this->shouldCheckOwnership() && $this->hasTableColumn(self::OWNERSHIP_COLUMN)) {
                $session = LoginService::getSession();
                $criteria[self::OWNERSHIP_COLUMN] = $session->getOwner();
            }

            $keys = array_keys($criteria);
            $where = implode(" AND ", array_map(function ($k) use ($criteria) {
                if(is_null($criteria[$k])){
                    return "$k is :$k";
                }

                if (is_array($criteria[$k])) {
                    return "$k IN (:$k)";
                }

                return "$k = :$k";

            }, $keys));

            $query = "SELECT $columnsSQL FROM `{$this->table}` WHERE $where $limitSQL";

            $this->db->query($query);
            foreach ($criteria as $key => $val) {
                $this->db->bind(":$key", $val);
            }

            return $this->db->fetchAll();
        } catch (PDOException $e) {
            if ($this->showError) echo $e->getMessage();
            return [];
        }
    }



    /**
     * @param array $columns
     * @return string
     */
    private function columnsQuery(Array $columns = [] ): string
    {

        $columnsSQl = "";

        // construct columns query
        // so that it can be fetched
        if( count($columns) > 0 ){
            for($i = 0; $i < count($columns); $i++){
                $columnsSQl .= $columns[$i];

                if( $i != (count($columns) - 1) ){
                    $columnsSQl .= ",";
                }
            }


        }else{
            $columnsSQl =  "*";
        }

        return $columnsSQl;
    }

    /**
     * @param array $data
     * @param array $values
     * @return array
     */
    public function sanitize(array $data = [], array $values = []): array
    {
        $dataSanitize = [];
        foreach($values as $key){
            $dataSanitize[$key] = $data[$key];
        }

        return $dataSanitize;
    }

    /**
     * @return int
     */
    public function getLastId(): int
    {
        try {
            return $this->db->lastId();
        } catch (PDOException $th) {

            if($this->showError){
                echo $th->getMessage();
            }

            return 0;
        }
    }

    public function paginateAndFilter(array $filters = [], int $page = 1, int $limit = 10): array {
        $offset = ($page - 1) * $limit;
        $conditions = [];
        $params = [];

        foreach ($filters as $field => $value) {
            if (!in_array($field, $this->fields)) {
                throw new InvalidArgumentException("Filter field '$field' is not allowed.");
            }

            $paramKey = ":$field";
            if (is_bool($value) || is_int($value)) {
                $conditions[] = "`$field` = $paramKey";
                $params[$field] = $value;
            } else {
                $conditions[] = "`$field` LIKE $paramKey";
                $params[$field] = "%$value%";
            }
        }

        $whereClause = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $this->db->query("SELECT * FROM `{$this->table}` $whereClause LIMIT :limit OFFSET :offset ");

        foreach ($params as $key => $value) {
            $this->db->bind(":$key", $value);
        }

        $this->db->bind(':limit', $limit, PDO::PARAM_INT);
        $this->db->bind(':offset', $offset, PDO::PARAM_INT);

        $data = $this->db->fetchAll();

        $this->db->query("SELECT COUNT(*) as total FROM `{$this->table}` $whereClause");
        foreach ($params as $key => $value) {
            $this->db->bind(":$key", $value);
        }


        $totalResult = $this->db->fetchOne();

        return [
            'data' => $data,
            'current_page' => $page,
            'limit' => $limit,
            'total' => (int)$totalResult->total,
            'last_page' => ceil($totalResult->total / $limit),
        ];
    }

    private function hasTableColumn(string $column): bool
    {
        try {
            $this->db->query("SHOW COLUMNS FROM `{$this->table}` LIKE '$column'");
            $result = $this->db->fetchOne();
            return $result !== false;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function shouldCheckOwnership() : bool
    {
        $session = LoginService::getSession();
        if (!$session) {
            return false;
        }

        return in_array($session->getLevel(), self::OWNERSHIP_LEVELS);
    }

    public function getAllVisible(): array
    {
        try {
            $this->db->query("SELECT * FROM `roles` WHERE `id_owner` = 0 OR `id_owner` = :owner");
            $this->db->bind(":owner", LoginService::getSession()->getOwner());
            return $this->db->fetchAll();
        } catch (\Exception $e) {
            if ($this->showError) echo $e->getMessage();
            return [];
        }
    }


}