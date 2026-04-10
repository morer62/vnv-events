<?php

namespace App\Services;

use App\Repositories\Connection;

class AffiliateService
{
    private $db;
    
    public function __construct()
    {
        $this->db = new Connection();
    }
    
    /**
     * Obtiene o crea el código de afiliado para un usuario
     */
    public function getOrCreateAffiliateCode(int $userId): string
    {
        // Buscar código existente
        $this->db->query("SELECT affiliate_code FROM affiliate_codes WHERE user_id = :user_id AND status = 'active'");
        $this->db->bind(":user_id", $userId);
        $this->db->execute();
        $existing = $this->db->fetchAll();
        
        if (!empty($existing)) {
            return $existing[0]->affiliate_code;
        }
        
        // Generar nuevo código
        $affiliateCode = $this->generateAffiliateCode($userId);
        
        // Guardar en la base de datos
        $this->db->query("INSERT INTO affiliate_codes (user_id, affiliate_code, status) VALUES (:user_id, :affiliate_code, 'active')");
        $this->db->bind(":user_id", $userId);
        $this->db->bind(":affiliate_code", $affiliateCode);
        $this->db->execute();
        
        return $affiliateCode;
    }
    
    /**
     * Genera un código de afiliado único
     */
    private function generateAffiliateCode(int $userId): string
    {
        $baseCode = 'AFF' . str_pad($userId, 6, '0', STR_PAD_LEFT);
        
        // Verificar que el código sea único
        $this->db->query("SELECT id FROM affiliate_codes WHERE affiliate_code = :code");
        $this->db->bind(":code", $baseCode);
        $this->db->execute();
        $exists = $this->db->fetchAll();
        
        if (!empty($exists)) {
            // Si existe, agregar timestamp
            $baseCode = 'AFF' . str_pad($userId, 6, '0', STR_PAD_LEFT) . '_' . time();
        }
        
        return $baseCode;
    }
    
    /**
     * Procesa un clic en enlace de afiliado
     */
    public function processAffiliateClick(string $affiliateCode, ?string $utmSource = null, ?string $utmMedium = null, ?string $utmCampaign = null): bool
    {
        try {
            // Convertir código a mayúsculas para consistencia
            $affiliateCode = strtoupper($affiliateCode);
            
            // Buscar el código de afiliado
            $this->db->query("SELECT user_id FROM affiliate_codes WHERE affiliate_code = :code AND status = 'active'");
            $this->db->bind(":code", $affiliateCode);
            $this->db->execute();
            $affiliate = $this->db->fetchAll();
            
            if (empty($affiliate)) {
                return false;
            }
            
            $referrerId = $affiliate[0]->user_id;
            
            // Incrementar contador de clics
            $this->db->query("UPDATE affiliate_codes SET clicks = clicks + 1 WHERE affiliate_code = :code");
            $this->db->bind(":code", $affiliateCode);
            $this->db->execute();
            
            // Guardar datos en cookie para el registro posterior
            $affiliateData = [
                'referrer_id' => $referrerId,
                'referral_code' => $affiliateCode,
                'utm_source' => $utmSource,
                'utm_medium' => $utmMedium,
                'utm_campaign' => $utmCampaign,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'cookie_id' => session_id(),
                'timestamp' => time()
            ];
            
            // Guardar en cookie (válida por 30 días)
            setcookie('affiliate_data', base64_encode(json_encode($affiliateData)), time() + (30 * 24 * 60 * 60), '/');
            
            return true;
            
        } catch (\Exception $e) {
            error_log("Error processing affiliate click: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Obtiene datos de afiliado desde la cookie
     */
    public function getAffiliateFromCookie(): ?array
    {
        if (!isset($_COOKIE['affiliate_data'])) {
            return null;
        }
        
        try {
            $data = json_decode(base64_decode($_COOKIE['affiliate_data']), true);
            
            // Verificar que la cookie no haya expirado (30 días)
            if (!isset($data['timestamp']) || (time() - $data['timestamp']) > (30 * 24 * 60 * 60)) {
                return null;
            }
            
            return $data;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Registra un referido cuando se completa el registro
     */
    public function registerReferral(int $referredUserId, array $affiliateData): bool
    {
        try {
            // Verificar que no exista ya un referido con estos datos
            $this->db->query("SELECT id FROM affiliate_referrals WHERE referrer_id = :referrer_id AND referred_id = :referred_id");
            $this->db->bind(":referrer_id", $affiliateData['referrer_id']);
            $this->db->bind(":referred_id", $referredUserId);
            $this->db->execute();
            $existing = $this->db->fetchAll();
            
            if (!empty($existing)) {
                return true; // Ya existe, no duplicar
            }
            
            // Insertar el referido
            $this->db->query("
                INSERT INTO affiliate_referrals 
                (referrer_id, referred_id, referral_code, utm_source, utm_medium, utm_campaign, ip_address, user_agent, cookie_id, status, confirmed_at) 
                VALUES 
                (:referrer_id, :referred_id, :referral_code, :utm_source, :utm_medium, :utm_campaign, :ip_address, :user_agent, :cookie_id, 'confirmed', NOW())
            ");
            
            $this->db->bind(":referrer_id", $affiliateData['referrer_id']);
            $this->db->bind(":referred_id", $referredUserId);
            $this->db->bind(":referral_code", $affiliateData['referral_code']);
            $this->db->bind(":utm_source", $affiliateData['utm_source']);
            $this->db->bind(":utm_medium", $affiliateData['utm_medium']);
            $this->db->bind(":utm_campaign", $affiliateData['utm_campaign']);
            $this->db->bind(":ip_address", $affiliateData['ip_address']);
            $this->db->bind(":user_agent", $affiliateData['user_agent']);
            $this->db->bind(":cookie_id", $affiliateData['cookie_id']);
            $this->db->execute();
            
            // Incrementar contador de conversiones
            $this->db->query("UPDATE affiliate_codes SET conversions = conversions + 1 WHERE affiliate_code = :code");
            $this->db->bind(":code", $affiliateData['referral_code']);
            $this->db->execute();
            
            // Limpiar la cookie
            setcookie('affiliate_data', '', time() - 3600, '/');
            
            return true;
            
        } catch (\Exception $e) {
            error_log("Error registering referral: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Obtiene estadísticas de afiliado para un usuario
     */
    public function getAffiliateStats(int $userId): ?object
    {
        try {
            // Primero obtener el código de afiliado del usuario
            $this->db->query("SELECT affiliate_code, clicks, conversions FROM affiliate_codes WHERE user_id = :user_id AND status = 'active'");
            $this->db->bind(":user_id", $userId);
            $this->db->execute();
            $affiliateCode = $this->db->fetchAll();
            
            if (empty($affiliateCode)) {
                return null; // No tiene código de afiliado
            }
            
            $code = $affiliateCode[0]->affiliate_code;
            $clicks = $affiliateCode[0]->clicks;
            $conversions = $affiliateCode[0]->conversions;
            
            // Buscar estadísticas usando el código de afiliado
            $this->db->query("
                SELECT 
                    COUNT(DISTINCT ar.referred_id) as total_referrals,
                    COUNT(DISTINCT CASE WHEN ar.status = 'confirmed' THEN ar.referred_id END) as confirmed_referrals,
                    COALESCE(SUM(CASE WHEN ac.status = 'pending' THEN ac.commission_amount END), 0) as pending_commissions,
                    COALESCE(SUM(CASE WHEN ac.status = 'approved' THEN ac.commission_amount END), 0) as approved_commissions,
                    COALESCE(SUM(CASE WHEN ac.status = 'paid' THEN ac.commission_amount END), 0) as paid_commissions,
                    COALESCE(SUM(ac.commission_amount), 0) as total_commissions_earned,
                    MAX(ar.created_at) as last_referral_date
                FROM affiliate_referrals ar
                LEFT JOIN affiliate_commissions ac ON ar.referred_id = ac.referred_id AND ac.referrer_id = :user_id
                WHERE ar.referral_code = :affiliate_code
            ");
            $this->db->bind(":user_id", $userId);
            $this->db->bind(":affiliate_code", $code);
            $this->db->execute();
            $stats = $this->db->fetchAll();
            
            if (!empty($stats)) {
                $result = $stats[0];
                $result->affiliate_code = $code;
                $result->clicks = $clicks;
                $result->conversions = $conversions;
                return $result;
            }
            
            return null;
            
        } catch (\Exception $e) {
            error_log("Error getting affiliate stats: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Obtiene la lista de referidos para un usuario
     */
    public function getReferrals(int $userId, int $limit = 50): array
    {
        try {
            // Primero obtener el código de afiliado del usuario
            $this->db->query("SELECT affiliate_code FROM affiliate_codes WHERE user_id = :user_id AND status = 'active'");
            $this->db->bind(":user_id", $userId);
            $this->db->execute();
            $affiliateCode = $this->db->fetchAll();
            
            error_log("DEBUG getReferrals - User ID: $userId, Affiliate codes found: " . count($affiliateCode));
            
            if (empty($affiliateCode)) {
                error_log("DEBUG getReferrals - No affiliate code found for user $userId");
                return []; // No tiene código de afiliado
            }
            
            $code = $affiliateCode[0]->affiliate_code;
            error_log("DEBUG getReferrals - Using affiliate code: $code");
            
            // Buscar referidos que usaron este código
            $this->db->query("
                SELECT 
                    ar.*, 
                    u.name, 
                    u.lastname,
                    u.email, 
                    ar.created_at as user_registered_at,
                    COALESCE(SUM(ac.commission_amount), 0) as total_commissions_generated
                FROM affiliate_referrals ar
                JOIN users u ON ar.referred_id = u.id
                LEFT JOIN affiliate_commissions ac ON ar.referred_id = ac.referred_id AND ac.referrer_id = :user_id
                WHERE ar.referral_code = :affiliate_code
                GROUP BY ar.id, u.id
                ORDER BY ar.created_at DESC
                LIMIT :limit
            ");
            $this->db->bind(":user_id", $userId);
            $this->db->bind(":affiliate_code", $code);
            $this->db->bind(":limit", $limit);
            $this->db->execute();
            
            $results = $this->db->fetchAll();
            error_log("DEBUG getReferrals - Found " . count($results) . " referrals");
            
            return $results;
            
        } catch (\Exception $e) {
            error_log("Error getting referrals: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Obtiene las comisiones de un afiliado
     */
    public function getCommissions(int $userId, int $limit = 50): array
    {
        try {
            $this->db->query("
                SELECT 
                    ac.*,
                    u.name as referred_name,
                    u.email as referred_email
                FROM affiliate_commissions ac
                JOIN users u ON ac.referred_id = u.id
                WHERE ac.referrer_id = :user_id
                ORDER BY ac.created_at DESC
                LIMIT :limit
            ");
            $this->db->bind(":user_id", $userId);
            $this->db->bind(":limit", $limit);
            $this->db->execute();
            
            return $this->db->fetchAll();
            
        } catch (\Exception $e) {
            error_log("Error getting commissions: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Crea una comisión por una transacción
     */
    public function createCommission(int $referredUserId, string $transactionType, float $grossAmount, string $transactionId, ?int $orderId = null, ?int $paymentId = null): bool
    {
        try {
            // Buscar si este usuario fue referido
            $this->db->query("SELECT referrer_id, id FROM affiliate_referrals WHERE referred_id = :referred_id AND status = 'confirmed'");
            $this->db->bind(":referred_id", $referredUserId);
            $this->db->execute();
            $referral = $this->db->fetchAll();
            
            if (empty($referral)) {
                return true; // No hay referido, no crear comisión
            }
            
            $referrerId = $referral[0]->referrer_id;
            $referralId = $referral[0]->id;
            
            // Calcular comisión (30% por defecto)
            $commissionRate = 30.00;
            $commissionAmount = $grossAmount * ($commissionRate / 100);
            
            // Insertar comisión
            $this->db->query("
                INSERT INTO affiliate_commissions 
                (referrer_id, referred_id, referral_id, transaction_type, transaction_id, order_id, payment_id, gross_amount, commission_rate, commission_amount, status) 
                VALUES 
                (:referrer_id, :referred_id, :referral_id, :transaction_type, :transaction_id, :order_id, :payment_id, :gross_amount, :commission_rate, :commission_amount, 'pending')
            ");
            
            $this->db->bind(":referrer_id", $referrerId);
            $this->db->bind(":referred_id", $referredUserId);
            $this->db->bind(":referral_id", $referralId);
            $this->db->bind(":transaction_type", $transactionType);
            $this->db->bind(":transaction_id", $transactionId);
            $this->db->bind(":order_id", $orderId);
            $this->db->bind(":payment_id", $paymentId);
            $this->db->bind(":gross_amount", $grossAmount);
            $this->db->bind(":commission_rate", $commissionRate);
            $this->db->bind(":commission_amount", $commissionAmount);
            $this->db->execute();
            
            return true;
            
        } catch (\Exception $e) {
            error_log("Error creating commission: " . $e->getMessage());
            return false;
        }
    }
}