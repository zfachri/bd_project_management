<?php

if (!function_exists('deliciousCamelcase')) {
    function deliciousCamelcase($str)
    {
        $formattedStr = '';
        $re = '/
          (?<=[a-z])
          (?=[A-Z])
        | (?<=[A-Z])
          (?=[A-Z][a-z])
        /x';
        $a = preg_split($re, $str);
        $formattedStr = implode(' ', $a);

        return $formattedStr;
    }
}

if (!function_exists('sha256Make')) {
    function sha256Make($txt1)
    {
        return app(\App\Helpers\Sha256Hasher::class)->make($txt1);
    }
}

if (!function_exists('sha256Check')) {
    function sha256Check($txt1, $txt2)
    {
        return app(\App\Helpers\Sha256Hasher::class)->check($txt1, $txt2);
    }
}

if (!function_exists('decTo36')) {
    function decTo36($val, $digits = 8)
    {
        $res = "";

        for ($i = 0; $i < $digits; $i++) {
            $digit = $val % 36;

            if ($digit >= 10) {
                $res = chr($digit + 55) . $res;
            } else {
                $res = $digit . $res;
            }

            $val = floor($val / 36);
        }

        return strtoupper($res);
    }
}
if (!function_exists('revertDecto36')) {
    function revertDecto36($data, $num = 8)
    {
        $data = strtoupper($data);
        $res = 0;
        for ($i = 0; $i < $num; $i++) {
            $digit = 0;
            if (!preg_match('/^\d+$/', $data[$i])) {
                $digit = strval(ord($data[$i])) - 55;
            } else {
                $digit = intval($data[$i]);
            }

            $digit = $digit * pow(36, ($num - 1) - $i);
            $res += $digit;
        }

        return $res;
    }
}

if (!function_exists('isPhoneNo')) {
    function isPhoneNo($inputPhoneNo)
    {
        // Define the regex pattern for validating phone numbers
        $pattern = '/^(\+?\d{1,4}|\d{1,4})?\d{9,15}$/';

        // Use preg_match to check if the input matches the pattern
        if (preg_match($pattern, $inputPhoneNo)) {
            return true;
        } else {
            return false;
        }
    }
}

if (!function_exists('hideEmailAddress')) {
    function hideEmailAddress($email)
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            [$first, $last] = explode('@', $email);
            $first = str_replace(substr($first, '3'), str_repeat('*', strlen($first) - 3), $first);
            $last = explode('.', $last);
            $last_domain = str_replace(substr($last['0'], '1'), str_repeat('*', strlen($last['0']) - 1), $last['0']);
            $hideEmailAddress = $first . '@' . $last_domain . '.' . $last['1'];

            return $hideEmailAddress;
        }
    }
}

// In a suitable place, such as app/Helpers/ValidationHelper.php

if (!function_exists('secure_pin')) {
    /**
     * Validate a secure PIN.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @param  \Illuminate\Validation\Rule  $fail
     * @return void
     */
    function secure_pin($attribute, $value, $fail)
    {
        // Check if the value is 6 digits long
        if (!preg_match('/^\d{6}$/', $value)) {
            return $fail('The :attribute must be exactly 6 digits.');
        }

        // Check for sequential numbers
        $sequential = '0123456789';
        $reverse_sequential = '9876543210';

        if (strpos($sequential, $value) !== false || strpos($reverse_sequential, $value) !== false) {
            return $fail('The :attribute should not be a sequential number.');
        }

        // Check for repeated patterns
        if (preg_match('/^(\d{3})\1$/', $value)) {
            return $fail('The :attribute should not contain repeated patterns.');
        }

        // Check for all digits being the same
        if (preg_match('/^(\d)\1{5}$/', $value)) {
            return $fail('The :attribute should not contain the same digit repeated.');
        }
    }
}



function random_string($length)
{
    $key = '';
    $keys = array_merge(range(0, 9), range('A', 'Z'));

    for ($i = 0; $i < $length; $i++) {
        $key .= $keys[array_rand($keys)];
    }

    return $key;
}

function random_numbersu($length=5)
{
    // Buat batas maksimum, misalnya kalau length=5 → 99999
    $maxValue = (10 ** $length) - 1;

    // Ambil angka acak antara 0 dan maxValue
    $rand = random_int(0, $maxValue);

    // Format jadi string dengan leading zero
    $str = sprintf('%0' . $length . 'd', $rand);

    return $str;
}
