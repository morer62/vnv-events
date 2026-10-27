-- Every VNV Events estimate/order in the invoice lifecycle must reference the
-- owner-level General Conditions contract. Future inserts are also protected
-- centrally by OrdersRepository::addWithExplicitOwner().
SET NAMES utf8mb4;
START TRANSACTION;

SET @general_conditions_id := (
    SELECT id
    FROM orders_contracts
    WHERE id_owner=2
      AND LOWER(TRIM(title)) IN ('general conditions','general terms and conditions')
    ORDER BY CASE WHEN LOWER(TRIM(title))='general conditions' THEN 0 ELSE 1 END, id
    LIMIT 1
);

UPDATE orders
SET id_contract=@general_conditions_id
WHERE id_owner=2
  AND COALESCE(is_archived,0)=0
  AND COALESCE(status_workflow,'') LIKE 'INVOICE_%'
  AND id_contract IS NULL
  AND @general_conditions_id IS NOT NULL;

COMMIT;

SELECT @general_conditions_id AS general_conditions_id;
