<?php

namespace App\Models\Traits;

use Illuminate\Support\Facades\Crypt;

trait Encryptable
{
		public function getAttribute($key)
		{
		    $value = parent::getAttribute($key);

		    if (isset($this->encryptable) && in_array($key, $this->encryptable) && !empty($value)) {
		        try {
		            $value = Crypt::decryptString($value);
		        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
		            return $value;
		        }
		    }

		    return $value;
		}

		public function setAttribute($key, $value)
		{
		    if (isset($this->encryptable) && in_array($key, $this->encryptable) && !empty($value)) {
		        $value = Crypt::encryptString($value);
		    }

		    return parent::setAttribute($key, $value);
		}
}