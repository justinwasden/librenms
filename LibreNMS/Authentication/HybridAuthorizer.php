<?php

namespace LibreNMS\Authentication;

use App\Facades\LibrenmsConfig;
use App\Models\User;
use LibreNMS\Exceptions\AuthenticationException;

/**
 * Hybrid Authentication Authorizer
 *
 * Provides fallback authentication by trying Active Directory first,
 * then falling back to local MySQL authentication if AD fails.
 * This allows both AD users and local admin accounts to coexist.
 */
class HybridAuthorizer extends AuthorizerBase
{
    protected static $HAS_AUTH_USERMANAGEMENT = true;
    protected static $CAN_UPDATE_USER = true;
    protected static $CAN_UPDATE_PASSWORDS = true;

    private ?ActiveDirectoryAuthorizer $adAuthorizer = null;
    private ?MysqlAuthorizer $mysqlAuthorizer = null;

    /**
     * Bind to Active Directory if configured
     * This is called by Laravel's auth system before authenticate()
     */
    public function bind($credentials = [])
    {
        if ($this->isADConfigured()) {
            try {
                $adAuth = $this->getADAuthorizer();
                return $adAuth->bind($credentials);
            } catch (\Exception $e) {
                // If AD bind fails, silently continue - MySQL doesn't need bind
                \Log::debug("Hybrid Auth: AD bind failed, will try MySQL: " . $e->getMessage());
            }
        }
        return false;
    }

    /**
     * Authenticate user credentials
     * Try Active Directory first, then fall back to MySQL
     */
    public function authenticate($credentials)
    {
        $username = $credentials['username'] ?? null;
        $password = $credentials['password'] ?? null;

        if (empty($username) || empty($password)) {
            throw new AuthenticationException('Username and password are required');
        }

        $lastException = null;

        // Try Active Directory authentication if configured
        if ($this->isADConfigured()) {
            try {
                $adAuth = $this->getADAuthorizer();
                if ($adAuth->authenticate($credentials)) {
                    return true;
                }
            } catch (AuthenticationException $e) {
                // Store exception but continue to MySQL fallback
                $lastException = $e;
            } catch (\Exception $e) {
                // Log other exceptions but continue to MySQL fallback
                \Log::error("Hybrid Auth: AD authentication error for user '$username': " . $e->getMessage());
                $lastException = $e;
            }
        }

        // Try MySQL authentication as fallback
        try {
            $mysqlAuth = $this->getMysqlAuthorizer();
            if ($mysqlAuth->authenticate($credentials)) {
                return true;
            }
        } catch (AuthenticationException $e) {
            // MySQL also failed
            $lastException = $e;
        } catch (\Exception $e) {
            // Log MySQL errors
            \Log::error("Hybrid Auth: MySQL authentication error for user '$username': " . $e->getMessage());
            $lastException = $e;
        }

        // Both authentication methods failed
        if ($lastException) {
            throw new AuthenticationException('Authentication failed');
        }

        throw new AuthenticationException('Authentication failed');
    }

    /**
     * Check if Active Directory is properly configured
     */
    private function isADConfigured(): bool
    {
        return LibrenmsConfig::has('auth_ad_url')
            && LibrenmsConfig::has('auth_ad_domain')
            && !empty(LibrenmsConfig::get('auth_ad_url'))
            && !empty(LibrenmsConfig::get('auth_ad_domain'));
    }

    /**
     * Get or create Active Directory authorizer instance
     */
    private function getADAuthorizer(): ActiveDirectoryAuthorizer
    {
        if ($this->adAuthorizer === null) {
            $this->adAuthorizer = new ActiveDirectoryAuthorizer();
        }
        return $this->adAuthorizer;
    }

    /**
     * Get or create MySQL authorizer instance
     */
    private function getMysqlAuthorizer(): MysqlAuthorizer
    {
        if ($this->mysqlAuthorizer === null) {
            $this->mysqlAuthorizer = new MysqlAuthorizer();
        }
        return $this->mysqlAuthorizer;
    }

    /**
     * Check if user exists in either AD or MySQL
     */
    public function userExists($username, $throw_exception = false)
    {
        // Check MySQL first (faster)
        if (User::where('username', $username)
            ->where(function ($query) {
                $query->where('auth_type', 'mysql')
                    ->orWhere('auth_type', 'active_directory')
                    ->orWhere('auth_type', 'hybrid')
                    ->orWhereNull('auth_type')
                    ->orWhere('auth_type', '');
            })->exists()) {
            return true;
        }

        // Check AD if configured
        if ($this->isADConfigured()) {
            try {
                return $this->getADAuthorizer()->userExists($username, $throw_exception);
            } catch (\Exception $e) {
                if ($throw_exception) {
                    throw $e;
                }
            }
        }

        return false;
    }

    /**
     * Get user ID - check MySQL first, then AD
     */
    public function getUserid($username)
    {
        // Try MySQL first
        $userId = User::where('username', $username)
            ->where(function ($query) {
                $query->where('auth_type', 'mysql')
                    ->orWhere('auth_type', 'active_directory')
                    ->orWhere('auth_type', 'hybrid')
                    ->orWhereNull('auth_type')
                    ->orWhere('auth_type', '');
            })->value('user_id');

        if ($userId) {
            return $userId;
        }

        // Try AD if configured
        if ($this->isADConfigured()) {
            try {
                return $this->getADAuthorizer()->getUserid($username);
            } catch (\Exception $e) {
                // AD lookup failed
            }
        }

        return -1;
    }

    /**
     * Get user data
     */
    public function getUser($user_id)
    {
        $user = User::find($user_id);
        if ($user) {
            return $user->toArray();
        }

        return false;
    }

    /**
     * Get roles - delegate to AD authorizer if user is from AD
     */
    public function getRoles(string $username): array|false
    {
        // Check if user exists in MySQL
        $user = User::where('username', $username)
            ->where(function ($query) {
                $query->where('auth_type', 'mysql')
                    ->orWhere('auth_type', 'hybrid')
                    ->orWhereNull('auth_type')
                    ->orWhere('auth_type', '');
            })->first();

        if ($user) {
            // MySQL user - use default role handling
            return false;
        }

        // Try AD roles if configured
        if ($this->isADConfigured()) {
            try {
                return $this->getADAuthorizer()->getRoles($username);
            } catch (\Exception $e) {
                return false;
            }
        }

        return false;
    }

    /**
     * Can update passwords for MySQL users only
     */
    public function canUpdatePasswords($username = '')
    {
        if (empty($username)) {
            return true;
        }

        // Check if this is a MySQL user
        $user = User::where('username', $username)
            ->where(function ($query) {
                $query->where('auth_type', 'mysql')
                    ->orWhere('auth_type', 'hybrid')
                    ->orWhereNull('auth_type')
                    ->orWhere('auth_type', '');
            })->first();

        if ($user) {
            return (bool) $user->can_modify_passwd;
        }

        // AD users cannot update passwords in LibreNMS
        return false;
    }
}
