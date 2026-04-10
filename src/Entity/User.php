<?php

namespace App\Entity;

class User
{
    public const ADMIN_USER_LEVEL = [1,2,3];
public const EXTERNAL_USER_LEVEL = [4,5,6];

    public static int $ADMIN_USER_LEVEL = 1;
    public static int $TEAM_USER_LEVEL = 4;
    public static int $CLIENT_USER_LEVEL = 5;
    public static int $MARKETING_USER_LEVEL = 6;

    private int $id;
    private string $name;
    private string $lastname;
    private string $email;
    
    private string $password;
    private ?string $phone;
    private ?string $phoneCode;
    private ?int $phoneValidation = 1;
    private ?string $membershipDueDate;
    private ?int $level;
    private ?int $id_owner;

    private ?int $allowChatWithClients = 0;

    private string $membership_type; 

    private ?float $hourly_rate = null;

     private int $is_active;

     private ?string $googleId = null;
    private ?string $googleToken = null;



    
 


    /***
     * @var UserPermissions2[] $permissions
     */
    private array $permissions2;
    
 
 
    /**
     * @param int $id
     * @param string $name
     * @param string $lastname
     * @param string $email
     * @param string $password
     * @param string|null $phone
     * @param int|null $phoneValidation
     * @param string|null $membershipDueDate
     * @param int|null $level
     * @param int|null $id_owner
     * @param int|null $is_active
     * @param string|null $membership_type
     * @param string|null $googleToken
 
     */
 
    public function __construct(int $id, string $name, string $lastname, string $email, string $password, ?string $phone, ?int $phoneValidation, ?string $membershipDueDate, ?int $level, ?string $phoneCode, ?int $id_owner, $permissions2, ?int $allowChatWithClients , string $membership_type, string $is_active,?string $googleId = null, ?string $googleToken = null)
    {
        $this->id = $id;
        $this->name = $name;
        $this->lastname = $lastname;
        $this->email = $email;
        $this->password = $password;
        $this->phone = $phone;
        $this->phoneValidation = $phoneValidation;
        $this->membershipDueDate = $membershipDueDate;
        $this->level = $level;
        $this->phoneCode = $phoneCode;
        $this->id_owner = $id_owner;
        $this->permissions2 = $permissions2;
        $this->allowChatWithClients = $allowChatWithClients ?? 0;
        $this->membership_type  = $membership_type ;
        $this->is_active  = $is_active ;
        $this->googleId = $googleId;
        $this->googleToken = $googleToken;
        
  

        
        

    }

    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): void
    {
        $this->id = $id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getLastname(): string
    {
        return $this->lastname;
    }

    public function setLastname(string $lastname): void
    {
        $this->lastname = $lastname;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): void
    {
        $this->email = $email;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): void
    {
        $this->password = $password;
    }

    public function getPhone(): string
    {
        return $this->phone;
    }

    public function setPhone(string $phone): void
    {
        $this->phone = $phone;
    }

    public function getPhoneValidation(): int
    {
        return $this->phoneValidation;
    }

    public function setPhoneValidation(int $phoneValidation): void
    {
        $this->phoneValidation = $phoneValidation;
    }

    public function getMembershipDueDate(): ?string
    {
        return $this->membershipDueDate;
    }

    public function setMembershipDueDate(string $membershipDueDate): void
    {
        $this->membershipDueDate = $membershipDueDate;
    }

    public function getIdOwner(): ?int
    {
        return $this->id_owner;
    }

    public function setIdOwner(?int $id_owner): void
    {
        $this->id_owner = $id_owner;
    }

    public function getHourlyRate(): ?float
    {
        return $this->hourly_rate;
    }

    public function setHourlyRate(?float $hourly_rate): void
    {
        $this->hourly_rate = $hourly_rate;
    }

    public function getMembershipType(): string {
    return $this->membership_type;
    }

    public function setMembershipType(string $type): void {
        $this->membership_type = $type;
    }

    public function getIsActive(): int  {
    return $this->is_active;
    }

    public function setIsActive(int $is_active): void {
        $this->is_active = $is_active;
    }


    public function getGoogleId(): ?string
    {
        return $this->googleId;
    }

    public function setGoogleId(?string $googleId): void
    {
        $this->googleId = $googleId;
    }

    public function getGoogleToken(): ?string
    {
        return $this->googleToken;
    }

    public function setGoogleToken(?string $googleToken): void
    {
        $this->googleToken = $googleToken;
    }

 
    

    public function hasActivePaidMembership(): bool {
        return $this->membership_type === 'PAID';
    }

    public function hasActiveMembership(): bool
    {
        if ($this->level === self::$ADMIN_USER_LEVEL) {
            return true;
        }

        if ($this->membershipDueDate === null) {
            return false;
        }

        $dueDate = new \DateTime($this->membershipDueDate);
        $now = new \DateTime();

        return $dueDate >= $now;
    }


    public function getLevel(): int
    {
        return $this->level;
    }

    public function setLevel(int $level): void
    {
        $this->level = $level;
    }

    public function getPhoneCode(): ?string
    {
        return $this->phoneCode;
    }

    public function setPhoneCode(?string $phoneCode): void
    {
        $this->phoneCode = $phoneCode;
    }

    public function getOwner(): ?int
    {
        if ($this->id_owner === null) {
            return $this->id;
        }

        return $this->id_owner;
    }

    public function getPermissions2(): array
    {
        return $this->permissions2;
    }

    public function setPermissions2(array $permissions2): void
    {
        $this->permissions2 = $permissions2;
    }

    public function getAllowChatWithClients(): int
    {
        return $this->allowChatWithClients ?? 0;
    }

    public function setAllowChatWithClients(int $allowChatWithClients): void
    {
        $this->allowChatWithClients = $allowChatWithClients;
    }


    public function hasPermissionForModule(string $module): bool {
        foreach ($this->permissions2 as $perm) {
            if ($perm->getModule() === $module) {
                return true;
            }
        }
        return false;
    }



    public static function hasPermission(User $user, string $module, string $action) : bool {

        $permissions = $user->getPermissions2();
        if (empty($permissions)) {
            return false;
        }

        foreach ($permissions as $permission) {
            if (strtolower($permission->getModule()) === strtolower($module) && strtolower($permission->getAction()) === strtolower($action)) {
                return true;
            }
        }

        return false;
    }
}