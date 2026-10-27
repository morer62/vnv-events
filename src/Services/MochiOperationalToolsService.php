<?php
namespace App\Services;

use App\Repositories\Connection;

/** Read-only, role-scoped operational queries used by Mochi. */
final class MochiOperationalToolsService
{
    private const ADMIN_TOOLS=['weekend_events','executed_events','largest_event','team_activity','payroll_hours','payroll_payments','crm','collections','estimates','opportunities','requests','contracts','contract_terms','team_members','catalog','knowledge_audit','warehouse','rewards','customer_orders','website'];
    private const TEAM_TOOLS=['my_events','my_tasks','catalog','website'];
    private const CLIENT_TOOLS=['my_events','catalog','website','rewards'];
    public function __construct(private int $ownerId,private string $siteKey='vnvevents',private ?Connection $db=null){$this->db??=new Connection();}

    public function select(string $message,int $level): string
    {
        $m=mb_strtolower($message);
        if(preg_match('/\b(evento|orden)\b.{0,80}\b(m[aá]s grande|mayor|highest|largest)\b|\b(m[aá]s grande|mayor|highest|largest)\b.{0,80}\b(evento|orden)\b/iu',$m))return 'largest_event';
        if(preg_match('/\b(horas?|reloj|clock|fich[oó]|registr[oó])\b.{0,100}\b(equipo|emplead[oa]|usuario|esta semana|semana|mes|a[nñ]o)\b|\b(cu[aá]ntas? horas?)\b/iu',$m))return 'payroll_hours';
        if(preg_match('/\b(n[oó]mina|payroll|pagad[oa]s?|pagamos|pag[oó]|recibi[oó]|dinero)\b/iu',$m)&&preg_match('/\b(emplead[oa]s?|equipo|team\s*members?|qui[eé]n|m[aá]s|mayor)\b/iu',$m))return 'payroll_payments';
        if(preg_match('/\b(eventos?|fechas?)\b.{0,100}\b(asignad[oa]|trabaj[oó]|worked|labor[oó])\b|\b(asignad[oa]|trabaj[oó]|worked|labor[oó])\b.{0,100}\b(eventos?|fechas?)\b/iu',$m))return 'team_activity';
        if(preg_match('/\b(eventos?|contratos?)\b.{0,90}\b(ejecutad[oa]s?|realizad[oa]s?|completad[oa]s?)\b|\b(ejecutad[oa]s?|realizad[oa]s?|completad[oa]s?)\b.{0,90}\b(eventos?|contratos?)\b/iu',$m))return 'executed_events';
        if(preg_match('/paella|lasagna|pasticho|gourmet|producto|paquete|caviar|comida|personas|add.?on|precio|cu[aá]nto cuesta|nochebuena|navidad/iu',$m))return 'catalog';
        if(preg_match('/punto|reward/iu',$m))return 'rewards';
        if(preg_match('/contenedor|warehouse|inventario|almac[eé]n|storage/iu',$m)||preg_match('/\b(d[oó]nde|ubica)\b.{0,100}\b(flores?|mantel|vaso|copa|globo|vela|decoraci[oó]n|material|silla|mesa|backdrop|servilleta|art[ií]culo)\b/iu',$m))return 'warehouse';
        if(preg_match('/\b(crm|leads?|prospectos?|clientes? potenciales?|seguimiento comercial)\b/iu',$m))return 'crm';
        if(preg_match('/\b(contrato|acuerdo|cl[aá]usula|t[eé]rminos?)\b.{0,100}\b(dice|indica|especifica|establece|incluye|revis|cu[aá]nto tiempo|llegar|instalar|montaje|acceso)\b|\b(dice|indica|especifica|establece|revis|cu[aá]nto tiempo|llegar|instalar|montaje|acceso)\b.{0,100}\b(contrato|acuerdo|cl[aá]usula|t[eé]rminos?)\b/iu',$m))return 'contract_terms';
        if(preg_match('/contrato|firma/iu',$m))return 'contracts';
        if(preg_match('/team\s*members?|miembros? del equipo|personas?.{0,60}(equipo|staff)|equipo.{0,60}(registrad|activ|miembros?|personas?)/iu',$m))return 'team_members';
        if(preg_match('/contradic|inconsisten|no coincide|diferencias?.{0,80}(cat[aá]logo|servicio|producto|landing|tienda|store)|comparar?.{0,80}(cat[aá]logo|servicio|producto|landing|tienda|store)/iu',$m))return 'knowledge_audit';
        if(preg_match('/\b(estimados?|estimates?|cotizaciones?)\b/iu',$m)&&preg_match('/\b(mu[eé]strame|mostrar|lista|listar|cu[aá]ntos?|cu[aá]les?|buscar|consulta|consultar|creados?|crearon|generados?|generaron|fecha de creaci[oó]n|hoy|ayer)\b/iu',$m))return 'estimates';
        if(preg_match('/estimado|lead|oportunidad|seguimiento/iu',$m))return 'opportunities';
        if(preg_match('/solicitud|request|unread|sin leer/iu',$m))return 'requests';
        if(preg_match('/saldo|debe|cobro|pago pendiente|outstanding/iu',$m))return 'collections';
        if(preg_match('/tarea/iu',$m))return $level===4?'my_tasks':'weekend_events';
        if(preg_match('/evento|agenda|calendario|manager|gerente|staff|equipo/iu',$m))return $level===1?'weekend_events':'my_events';
        if(preg_match('/servicio|p[aá]gina|website|sitio/iu',$m))return 'website';
        return 'website';
    }

    public function run(string $tool,int $userId,int $level,int $sessionId,string $message=''): array
    {
        $allowed=$level===1?self::ADMIN_TOOLS:($level===4?self::TEAM_TOOLS:($level===5?self::CLIENT_TOOLS:[]));$start=microtime(true);
        if(!in_array($tool,$allowed,true)){$this->log($userId,$sessionId,$tool,'DENIED',$start,0,'ROLE_SCOPE');throw new \RuntimeException('Esa información no está disponible para tu nivel de acceso.');}
        try{$result=match($tool){'weekend_events'=>$this->weekendEvents(),'executed_events'=>$this->executedEvents($message),'largest_event'=>$this->largestEvent($message),'team_activity'=>$this->teamActivity($message),'payroll_hours'=>$this->payrollHours($message),'payroll_payments'=>$this->payrollPayments($message),'crm'=>$this->crm($message),'my_events'=>$this->myEvents($userId,$level),'my_tasks'=>$this->myTasks($userId),'collections'=>$this->collections(),'estimates'=>$this->estimates($message),'opportunities'=>$this->opportunities(),'requests'=>$this->requests(),'contracts'=>$this->contracts(),'contract_terms'=>$this->contractTerms($message),'team_members'=>$this->teamMembers($message),'catalog'=>$this->catalog($message),'knowledge_audit'=>$this->knowledgeAudit($message),'warehouse'=>$this->warehouse($message),'rewards'=>$this->rewards($userId,$level,$message),'customer_orders'=>$this->myEvents($userId,$level),'website'=>$this->website($message)};$this->log($userId,$sessionId,$tool,'SUCCESS',$start,count($result['items']??[]));return ['tool'=>$tool]+$result;}
        catch(\Throwable $e){$this->log($userId,$sessionId,$tool,'FAILED',$start,0,'QUERY_FAILED');error_log('[Mochi tool '.$tool.'] '.$e->getMessage());throw new \RuntimeException('No pude recuperar esos datos ahora mismo. Intenta nuevamente.');}
    }

    public function suggestions(int $level): array {$this->db->query("SELECT title,prompt,icon FROM mochi_suggested_prompts WHERE id_owner=:owner AND site_key=:site AND user_level=:level AND status='ACTIVE' ORDER BY sort_order,id LIMIT 4");$this->bind(['owner'=>$this->ownerId,'site'=>$this->siteKey,'level'=>$level]);return $this->db->fetchAll();}

    private function weekendEvents(): array
    {
        [$start,$end]=$this->weekend();$sql="SELECT o.id,o.event_date,o.start_time,o.address,o.status_workflow,o.payment_status,o.main_manager_id,CONCAT_WS(' ',c.name,c.lastname) customer,CONCAT_WS(' ',m.name,m.lastname) manager,COALESCE((SELECT SUM(osa.subtotal) FROM orders_services_assigned osa WHERE osa.id_order=o.id),0) total,COALESCE((SELECT SUM(GREATEST(op.amount-COALESCE(op.refunded_amount,0),0)) FROM orders_payments op WHERE op.id_order=o.id AND (op.id_suborder IS NULL OR op.id_suborder=0) AND COALESCE(op.is_suborder,0)=0 AND COALESCE(op.is_refunded,0)=0),0) paid_total,COUNT(DISTINCT ott.id_user) team_count FROM orders o LEFT JOIN users c ON c.id=o.id_client LEFT JOIN users m ON m.id=o.main_manager_id LEFT JOIN orders_team_tasks ott ON ott.id_order=o.id WHERE o.id_owner=:owner AND COALESCE(o.is_archived,0)=0 AND o.event_date BETWEEN :start AND :end GROUP BY o.id ORDER BY o.event_date,o.start_time";$this->db->query($sql);$this->bind(['owner'=>$this->ownerId,'start'=>$start,'end'=>$end]);$rows=$this->db->fetchAll();$contracted=array_sum(array_map(fn($r)=>(float)$r->total,$rows));$paid=array_sum(array_map(fn($r)=>(float)$r->paid_total,$rows));$items=$this->rows($rows,fn($r)=>['title'=>'Evento #'.$r->id.' · '.($r->customer?:'Cliente sin nombre'),'meta'=>$r->event_date.' '.substr((string)$r->start_time,0,5).' · '.($r->address?:'Ubicación pendiente'),'detail'=>'Valor: $'.number_format((float)$r->total,2).' · Pagado: $'.number_format((float)$r->paid_total,2).' · Gerente: '.($r->manager?:'NO ASIGNADO').' · Equipo: '.(int)$r->team_count,'flag'=>!$r->main_manager_id?'Gerente no asignado':((int)$r->team_count===0?'Equipo no asignado':''),'url'=>'/panel/planner-hub/management/orders/orders/edit?id='.(int)$r->id]);return ['reply'=>count($items).' evento(s) entre '.$start.' y '.$end.'. Valor registrado: $'.number_format($contracted,2).'. Pagos netos registrados: $'.number_format($paid,2).'.','items'=>$items,'facts'=>['event_count'=>count($items),'period_start'=>$start,'period_end'=>$end,'registered_value'=>$contracted,'net_main_order_payments'=>$paid,'difference'=>$contracted-$paid,'payment_scope'=>'Pagos netos registrados en las órdenes principales, descontando reembolsos registrados.']];
    }
    private function executedEvents(string $message): array
    {
        $now=new \DateTimeImmutable('now',new \DateTimeZone('America/New_York'));
        $year=(int)$now->format('Y');if(preg_match('/\b(20\d{2})\b/',$message,$match))$year=(int)$match[1];
        $start=sprintf('%04d-01-01',$year);$yearEnd=sprintf('%04d-12-31',$year);$end=$year===(int)$now->format('Y')?$now->format('Y-m-d'):$yearEnd;
        if($year>(int)$now->format('Y'))$end=$start;
        $this->db->query("SELECT COUNT(*) total,MIN(event_date) first_date,MAX(event_date) last_date,COALESCE(SUM(GREATEST((subtotal-discount_amount)*(1+(tax_percentage/100)),0)),0) contracted_total,COALESCE(SUM(paid_total),0) paid_total FROM (SELECT o.id,o.event_date,COALESCE(o.tax_percentage,0) tax_percentage,COALESCE((SELECT SUM(s.subtotal) FROM orders_services_assigned s WHERE s.id_order=o.id),0) subtotal,CASE WHEN COALESCE(o.discount_type,'amount') IN ('percent','percentage') THEN COALESCE((SELECT SUM(s.subtotal) FROM orders_services_assigned s WHERE s.id_order=o.id),0)*COALESCE(o.discount_value,0)/100 ELSE COALESCE(o.discount_value,0) END discount_amount,COALESCE((SELECT SUM(GREATEST(p.amount-COALESCE(p.refunded_amount,0),0)) FROM orders_payments p WHERE p.id_order=o.id AND (p.id_suborder IS NULL OR p.id_suborder=0) AND COALESCE(p.is_suborder,0)=0 AND COALESCE(p.is_refunded,0)=0),0) paid_total FROM orders o WHERE o.id_owner=:owner AND COALESCE(o.is_archived,0)=0 AND o.event_date BETWEEN :start AND :end AND COALESCE(o.status_workflow,'') NOT IN ('INVOICE_DRAFT','INVOICE_EXPIRED','INVOICE_CANCELLED','CANCELLED')) q");
        $this->bind(['owner'=>$this->ownerId,'start'=>$start,'end'=>$end]);$row=$this->db->fetchOne();$total=(int)($row->total??0);
        $contracted=(float)($row->contracted_total??0);$paid=(float)($row->paid_total??0);
        $reply=$total.' evento(s) con fecha ya transcurrida en '.$year.'. Valor contratado registrado: $'.number_format($contracted,2).'. Pagos netos registrados en las órdenes principales: $'.number_format($paid,2).'. Excluí estimates en borrador, cancelados, expirados y registros archivados; no conté solamente contratos firmados. Estas son cifras operativas del sistema, no utilidad contable.';
        $items=[['title'=>'Eventos ejecutados en '.$year.': '.$total,'meta'=>'Contratado: $'.number_format($contracted,2).' · Pagado: $'.number_format($paid,2),'detail'=>'Criterio verificable: fecha del evento transcurrida; sin drafts, cancelados, expirados ni archivados. Los pagos corresponden a órdenes principales y descuentan reembolsos registrados.','url'=>'/panel/planner-hub/management/orders/orders/previous']];
        return ['reply'=>$reply,'items'=>$items,'facts'=>['year'=>$year,'event_count'=>$total,'contracted_total'=>$contracted,'net_main_order_payments'=>$paid,'difference'=>$contracted-$paid,'contracted_definition'=>'Valor de servicios registrados en las órdenes principales después de descuentos y con el impuesto configurado.','payments_definition'=>'Pagos netos registrados en las órdenes principales, descontando reembolsos.','accounting_limit'=>'Son cifras operativas del sistema; no equivalen por sí solas a utilidad ni a ingreso contable conciliado.']];
    }
    private function largestEvent(string $message): array
    {
        [$start,$end,$label]=$this->period($message,'year');
        $this->db->query("SELECT o.id,o.event_date,o.address,CONCAT_WS(' ',u.name,u.lastname) customer,COALESCE((SELECT SUM(s.subtotal) FROM orders_services_assigned s WHERE s.id_order=o.id),0) subtotal,COALESCE(o.discount_value,0) discount_value,COALESCE(o.discount_type,'amount') discount_type,COALESCE(o.tax_percentage,0) tax_percentage FROM orders o LEFT JOIN users u ON u.id=o.id_client WHERE o.id_owner=:owner AND COALESCE(o.is_archived,0)=0 AND o.event_date BETWEEN :start AND :end AND COALESCE(o.status_workflow,'') NOT IN ('INVOICE_DRAFT','INVOICE_EXPIRED') ORDER BY o.event_date,o.id");
        $this->bind(['owner'=>$this->ownerId,'start'=>$start,'end'=>$end]);$rows=$this->db->fetchAll();
        foreach($rows as $row){$subtotal=(float)$row->subtotal;$discount=$row->discount_type==='percentage'?$subtotal*(float)$row->discount_value/100:(float)$row->discount_value;$row->contracted_total=max(0,($subtotal-$discount)*(1+(float)$row->tax_percentage/100));}
        usort($rows,fn($a,$b)=>(float)$b->contracted_total<=>(float)$a->contracted_total);$top=array_slice($rows,0,10);
        $items=$this->rows($top,fn($r)=>['title'=>'Evento #'.$r->id.' · '.($r->customer?:'Cliente sin nombre'),'meta'=>$r->event_date.' · $'.number_format((float)$r->contracted_total,2),'detail'=>(string)($r->address?:'Ubicación pendiente'),'url'=>'/panel/planner-hub/management/orders/orders/edit?id='.(int)$r->id]);
        if(!$top)return ['reply'=>'No encontré eventos válidos en '.$label.'.','items'=>[],'facts'=>['period_start'=>$start,'period_end'=>$end,'criterion'=>'valor contratado registrado']];
        $winner=$top[0];
        return ['reply'=>'El evento de mayor valor contratado en '.$label.' es el #'.$winner->id.' por $'.number_format((float)$winner->contracted_total,2).'. Interpreté «más grande» por valor porque la orden no guarda un conteo estructurado de invitados.','items'=>$items,'facts'=>['period_start'=>$start,'period_end'=>$end,'criterion'=>'Mayor valor contratado de servicios, después de descuento e impuesto configurado.','order_id'=>(int)$winner->id,'event_date'=>(string)$winner->event_date,'contracted_total'=>(float)$winner->contracted_total,'candidate_count'=>count($rows)]];
    }

    private function teamActivity(string $message): array
    {
        [$start,$end,$label]=$this->period($message,'year');$matches=$this->teamMemberMatches($message);
        $today=(new \DateTimeImmutable('now',new \DateTimeZone('America/New_York')))->format('Y-m-d');
        if(preg_match('/\b(trabaj[oó]|worked|labor[oó]|hizo)\b/iu',$message)&&$start<=$today&&$end>$today){$end=$today;$label.=' hasta '.$today;}
        if(count($matches)!==1)return $this->teamMemberClarification($matches,$label);
        $member=$matches[0];
        $this->db->query("SELECT o.id,o.event_date,o.start_time,o.end_time,o.address,CONCAT_WS(' ',c.name,c.lastname) customer,TRIM(BOTH ',' FROM CONCAT(IF(o.main_manager_id=:manager,'manager,',''),IF(EXISTS(SELECT 1 FROM orders_staff_invites si WHERE si.id_order=o.id AND si.id_user=:invite_user AND si.is_confirmed=1),'confirmed_invite,',''),IF(EXISTS(SELECT 1 FROM orders_team_tasks tt WHERE tt.id_order=o.id AND tt.id_user=:task_user),'task_assignment,',''))) evidence FROM orders o LEFT JOIN users c ON c.id=o.id_client WHERE o.id_owner=:owner AND COALESCE(o.is_archived,0)=0 AND o.event_date BETWEEN :start AND :end AND (o.main_manager_id=:manager_where OR EXISTS(SELECT 1 FROM orders_staff_invites si2 WHERE si2.id_order=o.id AND si2.id_user=:invite_where AND si2.is_confirmed=1) OR EXISTS(SELECT 1 FROM orders_team_tasks tt2 WHERE tt2.id_order=o.id AND tt2.id_user=:task_where)) ORDER BY o.event_date,o.start_time");
        $this->bind(['manager'=>(int)$member->id,'invite_user'=>(int)$member->id,'task_user'=>(int)$member->id,'owner'=>$this->ownerId,'start'=>$start,'end'=>$end,'manager_where'=>(int)$member->id,'invite_where'=>(int)$member->id,'task_where'=>(int)$member->id]);$rows=$this->db->fetchAll();
        $items=$this->rows($rows,fn($r)=>['title'=>'Evento #'.$r->id.' · '.($r->customer?:'Cliente sin nombre'),'meta'=>$r->event_date.' '.substr((string)$r->start_time,0,5).'–'.substr((string)$r->end_time,0,5),'detail'=>'Evidencia: '.str_replace(['manager','confirmed_invite','task_assignment'],['gerente','invitación confirmada','tarea asignada'],(string)$r->evidence).' · '.($r->address?:'Ubicación pendiente'),'url'=>'/panel/planner-hub/management/orders/orders/edit?id='.(int)$r->id]);
        $dates=array_values(array_unique(array_map(fn($r)=>(string)$r->event_date,$rows)));
        return ['reply'=>$member->name.' aparece asignado o confirmado en '.count($rows).' evento(s) durante '.$label.'. Esto acredita asignación en el sistema, no asistencia física; las horas se verifican por separado en el reloj de payroll.','items'=>$items,'facts'=>['employee_id'=>(int)$member->id,'employee_name'=>(string)$member->name,'period_start'=>$start,'period_end'=>$end,'assigned_event_count'=>count($rows),'event_dates'=>$dates,'evidence_definition'=>'Gerente principal, invitación de staff confirmada o tarea asignada; no prueba por sí sola asistencia física.']];
    }

    private function payrollHours(string $message): array
    {
        [$start,$end,$label]=$this->period($message,'week');$matches=$this->teamMemberMatches($message);
        if(count($matches)!==1)return $this->teamMemberClarification($matches,$label);
        $member=$matches[0];
        $this->db->query("SELECT id,start_time,end_time,is_paid,notes,CASE WHEN end_time IS NULL THEN NULL ELSE TIMESTAMPDIFF(SECOND,start_time,end_time)/3600 END hours FROM payroll_hours WHERE id_owner=:owner AND id_user=:user AND start_time>=:start AND start_time<DATE_ADD(:end,INTERVAL 1 DAY) ORDER BY start_time");$this->bind(['owner'=>$this->ownerId,'user'=>(int)$member->id,'start'=>$start.' 00:00:00','end'=>$end.' 00:00:00']);$rows=$this->db->fetchAll();
        $completed=array_values(array_filter($rows,fn($r)=>$r->end_time!==null));$hours=array_sum(array_map(fn($r)=>(float)$r->hours,$completed));$open=count($rows)-count($completed);
        $items=$this->rows($rows,fn($r)=>['title'=>date('D, M j',strtotime((string)$r->start_time)).' · '.substr((string)$r->start_time,11,5).($r->end_time?'–'.substr((string)$r->end_time,11,5):' · sesión abierta'),'meta'=>$r->end_time?number_format((float)$r->hours,2).' horas':'Sin hora de salida','detail'=>($r->is_paid?'Pagado':'Pendiente de pago').($r->notes?' · '.$r->notes:''),'url'=>'/panel/planner-hub/management/payroll/pending']);
        return ['reply'=>$member->name.' tiene '.number_format($hours,2).' hora(s) completadas en '.count($completed).' sesión(es) durante '.$label.($open?' Además, tiene '.$open.' sesión(es) sin hora de salida; no las sumé.':''),'items'=>$items,'facts'=>['employee_id'=>(int)$member->id,'employee_name'=>(string)$member->name,'period_start'=>$start,'period_end'=>$end,'completed_hours'=>round($hours,2),'completed_sessions'=>count($completed),'open_sessions'=>$open,'calculation'=>'Suma exacta de end_time - start_time en payroll_hours; sesiones abiertas se excluyen.']];
    }

    private function payrollPayments(string $message): array
    {
        [$start,$end,$label]=$this->period($message,'year');
        $this->db->query("SELECT pp.id payment_id,pp.id_user,pp.paid_at,pp.hours_count,pp.hourly_rate_snapshot,TRIM(CONCAT_WS(' ',u.name,u.lastname)) employee,COUNT(DISTINCT h.id) linked_sessions,COALESCE(SUM(CASE WHEN h.end_time IS NOT NULL AND h.end_time>h.start_time THEN TIMESTAMPDIFF(SECOND,h.start_time,h.end_time)/3600 ELSE 0 END),0) paid_hours,COALESCE(SUM(CASE WHEN h.end_time IS NOT NULL AND h.end_time>h.start_time AND pp.hourly_rate_snapshot IS NOT NULL THEN (TIMESTAMPDIFF(SECOND,h.start_time,h.end_time)/3600)*pp.hourly_rate_snapshot ELSE 0 END),0) calculated_amount FROM payroll_payments pp JOIN users u ON u.id=pp.id_user LEFT JOIN payroll_hours h ON h.id_owner=pp.id_owner AND h.id_user=pp.id_user AND FIND_IN_SET(CAST(h.id AS CHAR),REPLACE(REPLACE(REPLACE(pp.hours_ids,'[',''),']',''),' ',''))>0 WHERE pp.id_owner=:owner AND pp.paid_at>=:start AND pp.paid_at<DATE_ADD(:end,INTERVAL 1 DAY) GROUP BY pp.id,pp.id_user,pp.paid_at,pp.hours_count,pp.hourly_rate_snapshot,u.name,u.lastname ORDER BY pp.paid_at");
        $this->bind(['owner'=>$this->ownerId,'start'=>$start.' 00:00:00','end'=>$end.' 00:00:00']);$rows=$this->db->fetchAll();$people=[];$unresolved=0;
        foreach($rows as $row){$id=(int)$row->id_user;if(!isset($people[$id]))$people[$id]=['id'=>$id,'name'=>(string)($row->employee?:'Empleado #'.$id),'payments'=>0,'hours'=>0.0,'amount'=>0.0,'unresolved'=>0];$people[$id]['payments']++;$people[$id]['hours']+=(float)$row->paid_hours;$people[$id]['amount']+=(float)$row->calculated_amount;$missing=(int)$row->linked_sessions<(int)$row->hours_count||$row->hourly_rate_snapshot===null;if($missing){$people[$id]['unresolved']++;$unresolved++;}}
        $people=array_values($people);usort($people,fn($a,$b)=>$b['amount']<=>$a['amount']);
        $items=array_map(fn($r)=>['title'=>$r['name'],'meta'=>'$'.number_format($r['amount'],2).' calculados · '.number_format($r['hours'],2).' horas','detail'=>$r['payments'].' pago(s) de nómina'.($r['unresolved']?' · '.$r['unresolved'].' con datos incompletos':''),'url'=>'/panel/planner-hub/management/payroll/paid?user_id='.$r['id']],array_slice($people,0,20));
        if(!$people)return ['reply'=>'No encontré pagos de nómina registrados durante '.$label.'.','items'=>[],'facts'=>['period_start'=>$start,'period_end'=>$end,'payment_count'=>0,'calculation'=>'No hay registros en payroll_payments para el período.']];
        $winner=$people[0];$reply='Según el historial de nómina, '.$winner['name'].' encabeza '.$label.' con $'.number_format($winner['amount'],2).' calculados sobre '.number_format($winner['hours'],2).' horas vinculadas en '.$winner['payments'].' pago(s).';
        if($unresolved)$reply.=' Hay '.$unresolved.' pago(s) del período con sesiones o tarifa incompletas; no inventé esos importes y el ranking podría cambiar al corregirlos.';
        return ['reply'=>$reply,'items'=>$items,'facts'=>['period_start'=>$start,'period_end'=>$end,'employee_count'=>count($people),'payment_count'=>count($rows),'top_employee_id'=>$winner['id'],'top_employee_name'=>$winner['name'],'top_calculated_amount'=>round($winner['amount'],2),'top_paid_hours'=>round($winner['hours'],2),'unresolved_payments'=>$unresolved,'calculation'=>'Importe calculado por cada pago: duración exacta de las sesiones clock-in/clock-out vinculadas multiplicada por hourly_rate_snapshot. payroll_payments no almacena un total monetario independiente.']];
    }

    private function crm(string $message): array
    {
        $this->db->query("SELECT COALESCE(s.name,'Sin estado') status,COUNT(*) total FROM crm_leads l LEFT JOIN crm_status s ON s.id=l.id_status WHERE l.id_owner=:owner AND COALESCE(l.archived,'NO')<>'YES' AND COALESCE(l.soft_delete,'NO')<>'YES' GROUP BY s.id,s.name ORDER BY total DESC,status");$this->db->bind(':owner',$this->ownerId);$statuses=$this->db->fetchAll();
        $this->db->query("SELECT l.id,l.name,l.created_at,COALESCE(s.name,'Sin estado') status,COALESCE(c.name,'Sin categoría') category,TRIM(CONCAT_WS(' ',u.name,u.lastname)) assigned_to FROM crm_leads l LEFT JOIN crm_status s ON s.id=l.id_status LEFT JOIN crm_categories c ON c.id=l.id_category LEFT JOIN users u ON u.id=l.id_user WHERE l.id_owner=:owner AND COALESCE(l.archived,'NO')<>'YES' AND COALESCE(l.soft_delete,'NO')<>'YES' ORDER BY l.created_at DESC,l.id DESC LIMIT 20");$this->db->bind(':owner',$this->ownerId);$leads=$this->db->fetchAll();
        $total=array_sum(array_map(fn($r)=>(int)$r->total,$statuses));$items=$this->rows($leads,fn($r)=>['title'=>(string)$r->name,'meta'=>$r->status.' · '.$r->category,'detail'=>'Creado: '.$r->created_at.' · Asignado a: '.($r->assigned_to?:'Sin asignar'),'url'=>'/panel/planner-hub/management/crm/lead/edit?id='.(int)$r->id]);$breakdown=[];foreach($statuses as $status)$breakdown[(string)$status->status]=(int)$status->total;
        return ['reply'=>'El CRM tiene '.$total.' lead(s) activos. Estados principales: '.implode(' · ',array_map(fn($r)=>$r->status.': '.(int)$r->total,array_slice($statuses,0,8))).'. Te muestro los '.count($items).' más recientes.','items'=>$items,'facts'=>['active_lead_count'=>$total,'status_breakdown'=>$breakdown,'recent_lead_count'=>count($items),'scope'=>'Leads del owner actual; excluye archivados y soft-deleted.']];
    }

    private function myEvents(int $userId,int $level): array {$scope=$level===5?'o.id_client=:user':'(o.main_manager_id=:user OR EXISTS(SELECT 1 FROM orders_team_tasks t WHERE t.id_order=o.id AND t.id_user=:user))';$this->db->query("SELECT DISTINCT o.id,o.event_date,o.start_time,o.address,o.status_workflow FROM orders o WHERE o.id_owner=:owner AND COALESCE(o.is_archived,0)=0 AND o.event_date>=CURDATE() AND {$scope} ORDER BY o.event_date,o.start_time LIMIT 20");$this->bind(['owner'=>$this->ownerId,'user'=>$userId]);$items=$this->rows($this->db->fetchAll(),fn($r)=>['title'=>'Evento #'.$r->id,'meta'=>$r->event_date.' '.substr((string)$r->start_time,0,5),'detail'=>(string)$r->address,'url'=>'/panel/event-execution?order_id='.(int)$r->id]);return ['reply'=>count($items).' evento(s) próximo(s) dentro de tu acceso.','items'=>$items];}
    private function myTasks(int $userId): array {$this->db->query("SELECT t.id,t.task_description,t.is_done,o.id order_id,o.event_date FROM orders_team_tasks t JOIN orders o ON o.id=t.id_order WHERE o.id_owner=:owner AND t.id_user=:user AND COALESCE(t.is_done,0)=0 AND o.event_date>=CURDATE() ORDER BY o.event_date LIMIT 30");$this->bind(['owner'=>$this->ownerId,'user'=>$userId]);$items=$this->rows($this->db->fetchAll(),fn($r)=>['title'=>(string)$r->task_description,'meta'=>'Evento #'.$r->order_id.' · '.$r->event_date,'detail'=>'Pendiente','url'=>'/panel/event-execution?order_id='.(int)$r->order_id]);return ['reply'=>count($items).' tarea(s) pendiente(s).','items'=>$items];}
    private function collections(): array {$this->db->query("SELECT q.*,GREATEST(q.subtotal+(q.subtotal*q.tax_percentage/100)-CASE WHEN q.discount_type='percent' THEN q.subtotal*q.discount_value/100 ELSE q.discount_value END,0) total FROM (SELECT o.id,o.event_date,o.payment_status,o.tax_percentage,COALESCE(o.discount_type,'fixed') discount_type,COALESCE(o.discount_value,0) discount_value,CONCAT_WS(' ',u.name,u.lastname) customer,COALESCE((SELECT SUM(s.subtotal) FROM orders_services_assigned s WHERE s.id_order=o.id),0) subtotal,COALESCE((SELECT SUM(p.amount-COALESCE(p.refunded_amount,0)) FROM orders_payments p WHERE p.id_order=o.id AND COALESCE(p.is_refunded,0)=0),0) paid FROM orders o LEFT JOIN users u ON u.id=o.id_client WHERE o.id_owner=:owner AND COALESCE(o.is_archived,0)=0) q HAVING total-paid>0 ORDER BY total-paid DESC LIMIT 10");$this->db->bind(':owner',$this->ownerId);$items=$this->rows($this->db->fetchAll(),fn($r)=>['title'=>($r->customer?:'Cliente').' · Evento #'.$r->id,'meta'=>'Saldo: $'.number_format((float)$r->total-(float)$r->paid,2).' · Total: $'.number_format((float)$r->total,2),'detail'=>'Pagado: $'.number_format((float)$r->paid,2).' · Evento: '.$r->event_date,'url'=>'/panel/planner-hub/management/orders/orders/edit?id='.(int)$r->id]);return ['reply'=>'Los '.count($items).' saldos pendientes más altos, ordenados por monto.','items'=>$items];}
    private function estimates(string $message): array
    {
        [$start,$end,$label]=$this->creationPeriod($message);
        $startAt=$start.' 00:00:00';
        $endAt=(new \DateTimeImmutable($end,new \DateTimeZone('America/New_York')))->modify('+1 day')->format('Y-m-d').' 00:00:00';
        $this->db->query("SELECT o.id,o.event_date,o.created_at,o.status_workflow,o.payment_status,o.tax_percentage,COALESCE(o.discount_type,'amount') discount_type,COALESCE(o.discount_value,0) discount_value,CONCAT_WS(' ',u.name,u.lastname) customer,u.email,u.phone,COALESCE((SELECT SUM(s.subtotal) FROM orders_services_assigned s WHERE s.id_order=o.id),0) subtotal FROM orders o LEFT JOIN users u ON u.id=o.id_client WHERE o.id_owner=:owner AND COALESCE(o.is_archived,0)=0 AND COALESCE(o.status_workflow,'') LIKE 'INVOICE_%' AND o.created_at>=:created_start AND o.created_at<:created_end ORDER BY o.created_at,o.id LIMIT 100");
        $this->bind(['owner'=>$this->ownerId,'created_start'=>$startAt,'created_end'=>$endAt]);$rows=$this->db->fetchAll();
        $statusLabels=['INVOICE_DRAFT'=>'Pendiente de firma','INVOICE_READY'=>'Listo para primer pago','INVOICE_PARTIAL'=>'Pago parcial','INVOICE_PAID'=>'Pagado','INVOICE_EXPIRED'=>'Expirado','INVOICE_CANCELLED'=>'Cancelado'];
        $items=[];$totals=[];$statuses=[];
        foreach($rows as $row){
            $subtotal=(float)$row->subtotal;$discountType=mb_strtolower((string)$row->discount_type);$discount=in_array($discountType,['percent','percentage'],true)?$subtotal*(float)$row->discount_value/100:(float)$row->discount_value;$total=max(0,($subtotal-$discount)*(1+(float)$row->tax_percentage/100));$status=(string)$row->status_workflow;$statuses[$status]=($statuses[$status]??0)+1;$totals[]=$total;
            $contact=trim(implode(' · ',array_filter([(string)$row->email,(string)$row->phone])));
            $items[]=['title'=>'Estimado #'.$row->id.' · '.($row->customer?:'Cliente sin nombre'),'meta'=>'$'.number_format($total,2).' · Evento: '.($row->event_date?:'Fecha pendiente'),'detail'=>'Creado: '.$row->created_at.' · Estado actual: '.($statusLabels[$status]??$status).($contact!==''?' · '.$contact:''),'url'=>'/panel/planner-hub/management/orders/orders/edit?id='.(int)$row->id];
        }
        $reply=$items?'Encontré '.count($items).' estimado(s) creado(s) '.$label.' según su fecha de creación. Total actual registrado: $'.number_format(array_sum($totals),2).'.':'No encontré estimados creados '.$label.' según la fecha de creación registrada.';
        return ['reply'=>$reply,'items'=>$items,'facts'=>['estimate_count'=>count($items),'created_start'=>$startAt,'created_end_exclusive'=>$endAt,'period_label'=>$label,'current_total'=>array_sum($totals),'current_status_counts'=>$statuses,'criterion'=>'orders.created_at dentro del período; registros INVOICE_% no archivados. El estado mostrado es el actual, aunque el estimate haya avanzado después de crearse.']];
    }
    private function opportunities(): array {$this->db->query("SELECT o.id,o.event_date,o.created_at,CONCAT_WS(' ',u.name,u.lastname) customer,COALESCE((SELECT SUM(s.subtotal) FROM orders_services_assigned s WHERE s.id_order=o.id),0) value FROM orders o LEFT JOIN users u ON u.id=o.id_client WHERE o.id_owner=:owner AND COALESCE(o.is_archived,0)=0 AND o.status_workflow='INVOICE_DRAFT' ORDER BY value DESC,o.created_at LIMIT 10");$this->db->bind(':owner',$this->ownerId);$items=$this->rows($this->db->fetchAll(),fn($r)=>['title'=>'Estimado #'.$r->id.' · '.($r->customer?:'Cliente'),'meta'=>'$'.number_format((float)$r->value,2).' · Evento: '.$r->event_date,'detail'=>'Prioridad factual: estimado abierto; creado '.$r->created_at,'url'=>'/panel/planner-hub/management/orders/orders/edit?id='.(int)$r->id]);return ['reply'=>count($items).' estimado(s) abierto(s), ordenados por valor.','items'=>$items];}
    private function requests(): array {$this->db->query("SELECT id,full_name,event_date,guest_count,status,created_at FROM event_requests WHERE id_owner=:owner AND COALESCE(is_archived,0)=0 AND status NOT IN ('CLOSED','CONVERTED') ORDER BY created_at LIMIT 10");$this->db->bind(':owner',$this->ownerId);$items=$this->rows($this->db->fetchAll(),fn($r)=>['title'=>'Solicitud #'.$r->id.' · '.$r->full_name,'meta'=>'Evento: '.$r->event_date.' · Invitados: '.(int)$r->guest_count,'detail'=>'Estado: '.$r->status.' · Recibida: '.$r->created_at,'url'=>'/panel/planner-hub/management/requests']);return ['reply'=>count($items).' solicitud(es) abiertas.','items'=>$items];}
    private function contracts(): array {$this->db->query("SELECT o.id,o.event_date,CONCAT_WS(' ',u.name,u.lastname) customer,CASE WHEN EXISTS(SELECT 1 FROM orders_acceptance_contracts ac WHERE ac.id_order=o.id) THEN 1 ELSE 0 END signed FROM orders o LEFT JOIN users u ON u.id=o.id_client WHERE o.id_owner=:owner AND COALESCE(o.is_archived,0)=0 AND o.event_date>=CURDATE() HAVING signed=0 ORDER BY o.event_date LIMIT 15");$this->db->bind(':owner',$this->ownerId);$items=$this->rows($this->db->fetchAll(),fn($r)=>['title'=>'Evento #'.$r->id.' · '.($r->customer?:'Cliente'),'meta'=>'Evento: '.$r->event_date,'detail'=>'Contrato sin firma registrada','url'=>'/panel/planner-hub/management/orders/orders/edit?id='.(int)$r->id]);return ['reply'=>count($items).' evento(s) próximo(s) sin firma registrada.','items'=>$items];}
    private function contractTerms(string $message): array
    {
        return (new MochiBusinessKnowledgeService($this->ownerId,$this->siteKey,$this->db))->contract($message);
    }
    private function teamMembers(string $message): array
    {
        $activeOnly=(bool)preg_match('/\b(activ[oa]s?|actualmente|vigentes?)\b/iu',$message);
        $sql="SELECT id,TRIM(CONCAT_WS(' ',name,lastname)) name,email,phone,is_active FROM users WHERE id_owner=:owner AND level=4".($activeOnly?' AND is_active=1':'')." ORDER BY is_active DESC,name,lastname";
        $this->db->query($sql);$this->db->bind(':owner',$this->ownerId);$rows=$this->db->fetchAll();
        $listRequested=(bool)preg_match('/\b(lista|listar|qui[eé]nes|nombres?|mu[eé]strame|detalle)\b/iu',$message);
        $items=$listRequested?$this->rows(array_slice($rows,0,30),fn($r)=>[
            'title'=>$r->name?:'Team Member #'.$r->id,
            'meta'=>(int)$r->is_active===1?'Activo':'Inactivo',
            'detail'=>trim(implode(' · ',array_filter([(string)$r->email,(string)$r->phone]))),
            'url'=>'/panel/planner-hub/management/users/edit?id='.(int)$r->id,
        ]):[];
        $activeCount=count(array_filter($rows,fn($r)=>(int)$r->is_active===1));
        return [
            'reply'=>$activeOnly?'Actualmente hay '.$activeCount.' Team Member(s) activos registrados.':'Hay '.count($rows).' Team Member(s) registrados: '.$activeCount.' activos y '.(count($rows)-$activeCount).' inactivos.',
            'items'=>$items,
            'facts'=>['active_only'=>$activeOnly,'team_member_count'=>count($rows),'active_team_member_count'=>$activeCount,'role_level'=>4],
        ];
    }
    private function catalog(string $message): array {return (new MochiBusinessKnowledgeService($this->ownerId,$this->siteKey,$this->db))->catalog($message);}
    private function knowledgeAudit(string $message): array {return (new MochiBusinessKnowledgeService($this->ownerId,$this->siteKey,$this->db))->audit($message);}
    private function warehouse(string $message): array
    {
        $this->db->query("SELECT c.id,c.name,COUNT(i.id) item_types,COALESCE(SUM(i.quantity),0) units FROM storage_containers c LEFT JOIN storage_items i ON i.id_container=c.id AND i.id_owner=c.id_owner WHERE c.id_owner=:owner GROUP BY c.id,c.name ORDER BY c.name");$this->db->bind(':owner',$this->ownerId);$containers=$this->db->fetchAll();
        $this->db->query("SELECT i.id,i.id_container,i.name,i.quantity,c.id current_container_id,c.name container_name FROM storage_items i LEFT JOIN storage_containers c ON c.id=i.id_container AND c.id_owner=i.id_owner WHERE i.id_owner=:owner ORDER BY i.name");$this->db->bind(':owner',$this->ownerId);$storedItems=$this->db->fetchAll();
        $stop=['donde','dónde','onde','tengo','estan','están','esta','está','guardado','guardada','guardados','guardadas','ubica','ubicado','ubicada','contenedor','inventario','almacen','almacén','warehouse','storage','busca','buscar','dime','mochi','articulo','artículo','item','los','las','uno','una','unos','unas','del','por','para'];$terms=array_values(array_filter(explode(' ',$this->plain($message)),fn($term)=>mb_strlen($term)>=3&&!in_array($term,$stop,true)));
        $score=static function(string $value,array $needles): int{$value=mb_strtolower($value);$points=0;foreach($needles as $term){if(str_contains($value,$term))$points+=10;elseif(mb_strlen($term)>=5){foreach(explode(' ',$value) as $word)if(levenshtein($term,$word)<=1){$points+=6;break;}}}return $points;};
        $matches=[];$orphanMatches=0;$minimumScore=max(1,count($terms)*6);
        if($terms){foreach($containers as $row){$points=$score($this->plain((string)$row->name),$terms);if($points>=$minimumScore)$matches[]=['score'=>$points,'item'=>['title'=>'Contenedor #'.$row->id.' · '.$row->name,'meta'=>(int)$row->units.' unidades vinculadas · '.(int)$row->item_types.' tipos','detail'=>'Contenedor actual de Storage','url'=>'/panel/planner-hub/management/storage/containers/items?container_id='.(int)$row->id]];}foreach($storedItems as $row){$points=$score($this->plain((string)$row->name),$terms);if($points<$minimumScore)continue;$orphan=$row->current_container_id===null;if($orphan)$orphanMatches++;$matches[]=['score'=>$points+($orphan?0:5),'item'=>['title'=>$row->name,'meta'=>(int)$row->quantity.' unidad(es) · '.($orphan?'Referencia antigua #'.$row->id_container:'Contenedor '.$row->container_name),'detail'=>$orphan?'El artículo existe, pero su contenedor anterior ya no existe; requiere conciliación de Storage.':'Ubicación verificada en Storage.','url'=>$orphan?'/panel/planner-hub/management/storage/containers':'/panel/planner-hub/management/storage/containers/items?container_id='.(int)$row->current_container_id]];}}
        if($matches){usort($matches,fn($a,$b)=>$b['score']<=>$a['score']);$items=array_map(fn($match)=>$match['item'],array_slice($matches,0,20));return ['reply'=>'Encontré '.count($matches).' coincidencia(s) en Storage. La ubicación más directa es '.$items[0]['title'].'.'.($orphanMatches?' También detecté '.$orphanMatches.' artículo(s) coincidente(s) con una referencia antigua de contenedor; los marqué sin inventar su ubicación actual.':''),'items'=>$items,'facts'=>['search_terms'=>$terms,'match_count'=>count($matches),'best_match'=>$items[0]['title'],'orphan_item_matches'=>$orphanMatches,'container_count'=>count($containers),'stored_item_count'=>count($storedItems)]];}
        if($terms)return ['reply'=>'No encontré ese artículo ni un contenedor coincidente en Storage. Hay '.count($containers).' contenedores y '.count($storedItems).' artículos registrados; no voy a inventar una ubicación.','items'=>[],'facts'=>['search_terms'=>$terms,'match_count'=>0,'container_count'=>count($containers),'stored_item_count'=>count($storedItems)]];
        $items=$this->rows(array_slice($containers,0,40),fn($r)=>['title'=>$r->name,'meta'=>(int)$r->units.' unidades vinculadas · '.(int)$r->item_types.' tipos','detail'=>'Contenedor de Storage','url'=>'/panel/planner-hub/management/storage/containers/items?container_id='.(int)$r->id]);$orphans=count(array_filter($storedItems,fn($r)=>$r->current_container_id===null));return ['reply'=>count($containers).' contenedor(es) y '.count($storedItems).' artículo(s) registrados en Storage.'.($orphans?' Detecté '.$orphans.' artículo(s) con referencias antiguas de contenedor que deben conciliarse.':''),'items'=>$items,'facts'=>['container_count'=>count($containers),'stored_item_count'=>count($storedItems),'orphan_item_count'=>$orphans]];
    }
    private function rewards(int $userId,int $level,string $message): array {if($level===1){$ids=$this->customerIds($message);if(count($ids)!==1)return ['reply'=>count($ids)>1?'Encontré varios clientes; escribe el nombre completo.':'Indica el nombre del cliente para consultar sus puntos.','items'=>[]];$userId=$ids[0];}$this->db->query("SELECT COALESCE(SUM(CASE WHEN status='AVAILABLE' THEN points ELSE 0 END),0) points,COALESCE(SUM(CASE WHEN status='AVAILABLE' THEN monetary_value ELSE 0 END),0) value FROM loyalty_transactions WHERE id_owner=:owner AND site_key=:site AND id_user=:user");$this->bind(['owner'=>$this->ownerId,'site'=>$this->siteKey,'user'=>$userId]);$r=$this->db->fetchOne();return ['reply'=>'Puntos disponibles: '.number_format((float)$r->points,2).' · Valor registrado: $'.number_format((float)$r->value,2).'. Los rewards no pueden usarse para tips.','items'=>[]];}
    private function website(string $message): array {$terms=array_values(array_filter(preg_split('/[^\pL\pN]+/u',mb_strtolower($message),-1,PREG_SPLIT_NO_EMPTY),fn($x)=>mb_strlen($x)>3&&!in_array($x,['servicio','servicios','ofrece','pagina','página','vnv','events'],true)));$where='';$params=['owner'=>$this->ownerId,'site'=>$this->siteKey];if($terms){$where=' AND ('.implode(' OR ',array_map(fn($i)=>"LOWER(CONCAT_WS(' ',c.title,c.excerpt,c.body_html)) LIKE :q{$i}",array_keys(array_slice($terms,0,3)))).')';foreach(array_slice($terms,0,3) as $i=>$term)$params['q'.$i]='%'.$term.'%';}$this->db->query("SELECT c.title,c.excerpt,r.route FROM cms_contents c JOIN cms_routes r ON r.id_content=c.id AND r.status='ACTIVE' WHERE c.id_owner=:owner AND c.site_key=:site AND c.status='PUBLISHED' {$where} ORDER BY c.updated_at DESC LIMIT 10");$this->bind($params);$items=$this->rows($this->db->fetchAll(),fn($r)=>['title'=>$r->title,'meta'=>'Servicio o contenido público','detail'=>trim(strip_tags((string)$r->excerpt)),'url'=>'/'.ltrim((string)$r->route,'/')]);return ['reply'=>$items?count($items).' página(s) relevante(s) del CMS.':'No encontré una página publicada coincidente. Puedes preguntarme por un servicio o producto concreto.','items'=>$items];}

    /** @return array{0:string,1:string,2:string} */
    private function creationPeriod(string $message): array
    {
        $timezone=new \DateTimeZone('America/New_York');$now=new \DateTimeImmutable('now',$timezone);$text=mb_strtolower(trim($message));
        if(preg_match('/\b(?:hoy|today)\b/iu',$text)){$day=$now;return [$day->format('Y-m-d'),$day->format('Y-m-d'),'hoy, '.$day->format('Y-m-d')];}
        if(preg_match('/\b(?:ayer|yesterday)\b/iu',$text)){$day=$now->modify('-1 day');return [$day->format('Y-m-d'),$day->format('Y-m-d'),'ayer, '.$day->format('Y-m-d')];}
        $explicitRange=$this->namedMonthRange($text,(int)$now->format('Y'));
        if($explicitRange!==null)return $explicitRange;
        if(preg_match('/\b(20\d{2})-(\d{1,2})-(\d{1,2})\b/',$text,$m)&&checkdate((int)$m[2],(int)$m[3],(int)$m[1])){$day=sprintf('%04d-%02d-%02d',(int)$m[1],(int)$m[2],(int)$m[3]);return [$day,$day,'el '.$day];}
        if(preg_match('/\b(\d{1,2})[\/-](\d{1,2})[\/-](20\d{2})\b/',$text,$m)&&checkdate((int)$m[2],(int)$m[1],(int)$m[3])){$day=sprintf('%04d-%02d-%02d',(int)$m[3],(int)$m[2],(int)$m[1]);return [$day,$day,'el '.$day];}
        $months=['enero'=>1,'january'=>1,'febrero'=>2,'february'=>2,'marzo'=>3,'march'=>3,'abril'=>4,'april'=>4,'mayo'=>5,'may'=>5,'junio'=>6,'june'=>6,'julio'=>7,'july'=>7,'agosto'=>8,'august'=>8,'septiembre'=>9,'setiembre'=>9,'september'=>9,'octubre'=>10,'october'=>10,'noviembre'=>11,'november'=>11,'diciembre'=>12,'december'=>12];
        foreach($months as $name=>$month)if(preg_match('/\b(\d{1,2})\s+(?:de\s+)?'.preg_quote($name,'/').'\s+(?:de\s+)?(20\d{2})\b/iu',$text,$m)&&checkdate($month,(int)$m[1],(int)$m[2])){$day=sprintf('%04d-%02d-%02d',(int)$m[2],$month,(int)$m[1]);return [$day,$day,'el '.$m[1].' de '.$name.' de '.$m[2]];}
        if(preg_match('/\b(?:[uú]ltimos?|last)\s+(\d{1,3})\s+(?:d[ií]as|days)\b/iu',$text,$m)){$days=max(1,min(366,(int)$m[1]));$start=$now->modify('-'.($days-1).' days');return [$start->format('Y-m-d'),$now->format('Y-m-d'),'durante los últimos '.$days.' días'];}
        return $this->period($message,'year');
    }

    /** @return array{0:string,1:string,2:string} */
    private function period(string $message,string $default='year'): array
    {
        $now=new \DateTimeImmutable('now',new \DateTimeZone('America/New_York'));$text=mb_strtolower($message);$year=(int)$now->format('Y');
        if(preg_match('/\b(20\d{2})\b/',$text,$m))$year=(int)$m[1];
        $explicitRange=$this->namedMonthRange($text,$year);
        if($explicitRange!==null)return $explicitRange;
        if(preg_match('/\b(20\d{2}-\d{2}-\d{2})\b.{0,120}\b(20\d{2}-\d{2}-\d{2})\b/u',$text,$m)){
            $first=new \DateTimeImmutable($m[1],new \DateTimeZone('America/New_York'));$second=new \DateTimeImmutable($m[2],new \DateTimeZone('America/New_York'));if($first>$second)[$first,$second]=[$second,$first];
            return [$first->format('Y-m-d'),$second->format('Y-m-d'),'el período '.$first->format('Y-m-d').' a '.$second->format('Y-m-d')];
        }
        if(preg_match('/\b(?:esta|this)\s+(?:semana|week)\b/iu',$text)){$start=$now->modify('monday this week');$end=$start->modify('+6 days');return [$start->format('Y-m-d'),$end->format('Y-m-d'),'esta semana ('.$start->format('Y-m-d').' a '.$end->format('Y-m-d').')'];}
        if(preg_match('/\b(?:semana\s+pasada|last\s+week)\b/iu',$text)){$start=$now->modify('monday last week');$end=$start->modify('+6 days');return [$start->format('Y-m-d'),$end->format('Y-m-d'),'la semana pasada ('.$start->format('Y-m-d').' a '.$end->format('Y-m-d').')'];}
        if(preg_match('/\b(?:[uú]ltimos?|last)\s+(30|60|90)\s+(?:d[ií]as|days)\b/iu',$text,$m)){$days=(int)$m[1];$start=$now->modify('-'.($days-1).' days');return [$start->format('Y-m-d'),$now->format('Y-m-d'),'los últimos '.$days.' días'];}
        if(preg_match('/\b(?:a[nñ]o|year)\s+(20\d{2})\b|\b(?:todo|entero|completo|full)\s+(?:el\s+)?(?:a[nñ]o\s+)?(20\d{2})\b/iu',$text,$m)){$fullYear=(int)($m[1]?:$m[2]);return [sprintf('%04d-01-01',$fullYear),sprintf('%04d-12-31',$fullYear),'el año '.$fullYear];}
        $months=['enero'=>1,'january'=>1,'febrero'=>2,'february'=>2,'marzo'=>3,'march'=>3,'abril'=>4,'april'=>4,'mayo'=>5,'may'=>5,'junio'=>6,'june'=>6,'julio'=>7,'july'=>7,'agosto'=>8,'august'=>8,'septiembre'=>9,'setiembre'=>9,'september'=>9,'octubre'=>10,'october'=>10,'noviembre'=>11,'november'=>11,'diciembre'=>12,'december'=>12];
        foreach($months as $name=>$month)if(preg_match('/\b'.preg_quote($name,'/').'\b/iu',$text)){$start=new \DateTimeImmutable(sprintf('%04d-%02d-01',$year,$month),new \DateTimeZone('America/New_York'));$end=$start->modify('last day of this month');return [$start->format('Y-m-d'),$end->format('Y-m-d'),$name.' de '.$year];}
        if($default==='week'){$start=$now->modify('monday this week');$end=$start->modify('+6 days');return [$start->format('Y-m-d'),$end->format('Y-m-d'),'esta semana ('.$start->format('Y-m-d').' a '.$end->format('Y-m-d').')'];}
        return [sprintf('%04d-01-01',$year),sprintf('%04d-12-31',$year),'el año '.$year];
    }

    /** @return array{0:string,1:string,2:string}|null */
    private function namedMonthRange(string $text,int $defaultYear): ?array
    {
        $months=['enero'=>1,'january'=>1,'febrero'=>2,'february'=>2,'marzo'=>3,'march'=>3,'abril'=>4,'april'=>4,'mayo'=>5,'may'=>5,'junio'=>6,'june'=>6,'julio'=>7,'july'=>7,'agosto'=>8,'august'=>8,'septiembre'=>9,'setiembre'=>9,'september'=>9,'octubre'=>10,'october'=>10,'noviembre'=>11,'november'=>11,'diciembre'=>12,'december'=>12];
        $monthPattern=implode('|',array_map(static fn(string $name): string=>preg_quote($name,'/'),array_keys($months)));
        $pattern='/\b(?:del|de|entre|from)?\s*(\d{1,2})\s*(?:de\s+)?(?:('.$monthPattern.')\s*)?(?:al|a|hasta|y|to|through|-)\s*(\d{1,2})\s*(?:de\s+)?('.$monthPattern.')(?:\s+(?:de\s+)?(20\d{2}))?\b/iu';
        if(!preg_match($pattern,$text,$match))return null;
        $startDay=(int)$match[1];$endDay=(int)$match[3];$startMonthName=mb_strtolower($match[2]!==''?$match[2]:$match[4]);$endMonthName=mb_strtolower($match[4]);$year=isset($match[5])&&$match[5]!==''?(int)$match[5]:$defaultYear;$startMonth=$months[$startMonthName]??0;$endMonth=$months[$endMonthName]??0;
        if(!checkdate($startMonth,$startDay,$year)||!checkdate($endMonth,$endDay,$year))return null;
        $timezone=new \DateTimeZone('America/New_York');$start=new \DateTimeImmutable(sprintf('%04d-%02d-%02d',$year,$startMonth,$startDay),$timezone);$end=new \DateTimeImmutable(sprintf('%04d-%02d-%02d',$year,$endMonth,$endDay),$timezone);
        if($start>$end)[$start,$end]=[$end,$start];
        return [$start->format('Y-m-d'),$end->format('Y-m-d'),'del '.$start->format('Y-m-d').' al '.$end->format('Y-m-d')];
    }

    /** @return object[] */
    private function teamMemberMatches(string $message): array
    {
        $this->db->query("SELECT id,TRIM(CONCAT_WS(' ',name,lastname)) name,email,is_active FROM users WHERE id_owner=:owner AND level=4 ORDER BY is_active DESC,name,lastname");$this->db->bind(':owner',$this->ownerId);$rows=$this->db->fetchAll();$text=$this->plain($message);$scored=[];
        foreach($rows as $row){$full=$this->plain((string)$row->name);$parts=array_values(array_filter(explode(' ',$full),fn($part)=>mb_strlen($part)>=3));$score=0;if($full!==''&&str_contains($text,$full))$score=100+mb_strlen($full);else foreach($parts as $part)if(preg_match('/\b'.preg_quote($part,'/').'\b/u',$text))$score+=10+mb_strlen($part);if($score>0)$scored[]=['score'=>$score,'row'=>$row];}
        usort($scored,fn($a,$b)=>$b['score']<=>$a['score']);if(!$scored)return [];$best=$scored[0]['score'];return array_values(array_map(fn($entry)=>$entry['row'],array_filter($scored,fn($entry)=>$entry['score']===$best)));
    }

    private function teamMemberClarification(array $matches,string $label): array
    {
        if(!$matches)return ['reply'=>'No pude identificar a qué integrante del equipo te refieres para '.$label.'. Dime su nombre o apellido.','items'=>[],'facts'=>['match_count'=>0,'period'=>$label]];
        $items=$this->rows(array_slice($matches,0,10),fn($r)=>['title'=>(string)$r->name,'meta'=>(int)$r->is_active===1?'Activo':'Inactivo','detail'=>(string)$r->email,'url'=>'/panel/planner-hub/management/users/edit?id='.(int)$r->id]);
        return ['reply'=>'Encontré varias personas que coinciden. Elige una para revisar '.$label.'.','items'=>$items,'facts'=>['match_count'=>count($matches),'period'=>$label]];
    }

    private function plain(string $value): string
    {
        $value=mb_strtolower(trim($value));$ascii=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$value);$value=$ascii!==false?$ascii:$value;return trim(preg_replace('/[^a-z0-9]+/',' ',$value)??$value);
    }

    private function weekend(): array {$now=new \DateTimeImmutable('now',new \DateTimeZone('America/New_York'));$friday=$now->modify('friday this week');if((int)$now->format('N')>5)$friday=$now->modify('friday last week');return [$friday->format('Y-m-d'),$friday->modify('+2 days')->format('Y-m-d')];}
    private function customerIds(string $message): array {$tokens=array_slice(array_values(array_filter(preg_split('/[^\pL\pN@._-]+/u',mb_strtolower($message),-1,PREG_SPLIT_NO_EMPTY),fn($x)=>mb_strlen($x)>3&&!in_array($x,['puntos','cliente','cuantos','cuántos','tiene','disponible','mochi'],true))),0,4);if(!$tokens)return [];$where=[];foreach($tokens as $i=>$t)$where[]="LOWER(CONCAT_WS(' ',name,lastname)) LIKE :n{$i}";$this->db->query("SELECT id FROM users WHERE id_owner=:owner AND level=5 AND (".implode(' OR ',$where).") LIMIT 5");$this->db->bind(':owner',$this->ownerId);foreach($tokens as $i=>$t)$this->db->bind(':n'.$i,'%'.$t.'%');return array_map(fn($r)=>(int)$r->id,$this->db->fetchAll());}
    private function rows(array $rows,callable $map): array {return array_map($map,$rows);}
    private function bind(array $values): void {foreach($values as $key=>$value)$this->db->bind(':'.$key,$value);}
    private function log(int $user,int $session,string $tool,string $status,float $start,int $count,string $error=null): void {try{$this->db->query("INSERT INTO mochi_tool_logs(id_owner,site_key,id_user,id_session,tool_key,status,duration_ms,result_count,error_code) VALUES(:owner,:site,:user,:session,:tool,:status,:duration,:count,:error)");$this->bind(['owner'=>$this->ownerId,'site'=>$this->siteKey,'user'=>$user,'session'=>$session,'tool'=>$tool,'status'=>$status,'duration'=>(int)((microtime(true)-$start)*1000),'count'=>$count,'error'=>$error]);$this->db->execute();}catch(\Throwable){} }
}
