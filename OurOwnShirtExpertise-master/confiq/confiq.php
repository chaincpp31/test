<?php
  $server = "localhost";
  $server_url = "worawanbydiistudent.store";
  $user = "";
  $password = "";
  $database = "";
  $connect = new mysqli($server, $user, $password, $database);
  if ($connect->connect_errno) {
    printf("connection timed out : [fetal]");
    exit();
  }
  session_start();
  function argon2_encrypt($text) {
    return password_hash($text, PASSWORD_ARGON2I);
  }
  function random_string() {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!@#$%^&*()_+=-~/?>.,<\|฿';
    $characters_length = strlen($characters);
    $random_string = '';
    $length = 5;
    for ($i = 0; $i < $length; $i++) {
      $random_string .= $characters[rand(0, $characters_length - 1)];
    }
    return $random_string;
  }
  function session_restore_result(mysqli $connect, $server_url) {
    if(isset($_COOKIE['current_userid']) && !is_null($_COOKIE['current_userid']) && isset($_COOKIE['encrypted_hash_key']) && !is_null($_COOKIE['encrypted_hash_key'])) {
      $userid = $_COOKIE['current_userid'];
      $server_decrypted_hash_key = $connect->query("select * from usercredentials where userid='".$userid."'");
      if ($server_decrypted_hash_key->num_rows == 0) {
        printf("Could not detect user account from cookie. this shouldn't happen and should be checked before : [fetal]");
        exit();
      }
      $row = $server_decrypted_hash_key->fetch_assoc();
      if (password_verify($row['userhashkey'], $_COOKIE['encrypted_hash_key'])) {
        $decrypted_hash_key = random_string();
        $encrypted_hash_key = argon2_encrypt($decrypted_hash_key);
        setcookie('encrypted_hash_key', $encrypted_hash_key, time() + 3600, '/', $server_url, false, true);
        setcookie('current_userid', $userid, time() + 3600, '/', $server_url, false, true);
        $hash_key_update_result = $connect->query("update usercredentials set userhashkey='".$decrypted_hash_key."' where userid='".$userid."'");
        if (!$hash_key_update_result) {
          if (isset($_COOKIE['encrypted_hash_key'])) {
            setcookie('encrypted_hash_key', null, -1, '/', $server_url, false, true);
          }
          if (isset($_COOKIE['current_userid'])) {
            setcookie('current_userid', null, -1, '/', $server_url, false, true);
          }
          printf("session restore failed : [fetal]");
          exit();
        }
        return true;
      } else {
        $hash_key_update_result = $connect->query("update usercredentials set userhashkey=NULL where userid='".$userid."'");
        if (isset($_COOKIE['current_userid'])) {
          setcookie('current_userid', null, -1, '/', $server_url, false, true);
        }
        if (isset($_COOKIE['encrypted_hash_key'])) {
          setcookie('encrypted_hash_key', null, -1, '/', $server_url, false, true);
        }
        if (!$hash_key_update_result) {
          printf("destroy server hashkey failed. userid : '".$userid."' doesn't exists on server. this shouldn't occur as we already checked before : [fetal]");
          exit();
        }
        return false;
      }
    } else {
      if (isset($_COOKIE['current_userid'])) {
        setcookie('current_userid', null, -1, '/', $server_url, false, true);
      }
      if (isset($_COOKIE['encrypted_hash_key'])) {
        setcookie('encrypted_hash_key', null, -1, '/', $server_url, false, true);
      }
      return false;
    }
  }
  function login_result(mysqli $connect, $server_url, $username, $vulnerable_password) {
    if ($username == null) {
      $try_to_get_passkey_tmp_string = ['userpassword' => 'keynotavailable'];
    } else {
      $try_to_get_passkey_tmp = $connect->query("select userpassword from usercredentials where userid='".$username."'");
      $try_to_get_passkey_tmp_string = $try_to_get_passkey_tmp->fetch_assoc();
    }
    if (password_verify($vulnerable_password, $try_to_get_passkey_tmp_string['userpassword']) && !empty($try_to_get_passkey_tmp->num_rows)) {
      $decrypted_hash_key_tmp = random_string();
      $encrypted_hash_key_tmp = argon2_encrypt($decrypted_hash_key_tmp);
      $encrypted_administration_key_tmp = $connect->query("select * from usercredentials where userid='".$username."'");
      $encrypted_administration_key_tmp_string = $encrypted_administration_key_tmp->fetch_assoc();
      setcookie('encrypted_hash_key', $encrypted_hash_key_tmp, time() + 3600, '/', $server_url, false, true);
      setcookie('current_userid', $username, time() + 3600, '/', $server_url, false, true);
      $hash_key_update_result = $connect->query("update usercredentials set userhashkey='".$decrypted_hash_key_tmp."' where userid='".$username."'");
      if(!$hash_key_update_result) {
        if (isset($_COOKIE['encrypted_hash_key'])) {
          setcookie('encrypted_hash_key', null, -1, '/', $server_url, false, true);
        }
        if (isset($_COOKIE['current_userid'])) {
          setcookie('current_userid', null, -1, '/', $server_url, false, true);
        }
        printf("process failed during server hash_key update : ".$username." hashkey : ".$decrypted_hash_key_tmp." : [fetal]");
        exit();
      }
      return true;
    }
    return false;
  }
  function register_result(mysqli $connect, $username, $vulnerable_password, $vulnerable_password_retype, $name, $lastname, $primary_address, $secondary_address, $city, $state, $provice, $postcode, $phonenumber, $emailaddress) {
    $server_userid_check = $connect->query("select * from usercredentials");
    while($row = $server_userid_check->fetch_assoc()) {
      if ($row['userid'] == $username) {
        return ['username_valid' => false,
                'password_valid' => false,
                'email_valid' => false
        ];
      }
    }
    if ($vulnerable_password != $vulnerable_password_retype || $vulnerable_password == 'keynotavailable' || is_null($vulnerable_password)) {
      return ['username_valid' => true,
              'password_valid' => false,
              'email_valid' => false
      ];
    }
    $encrypted_administration_key_tmp = argon2_encrypt(random_string());
    $encrypted_password_tmp = argon2_encrypt($vulnerable_password);
    $try_to_register_credentials_result = $connect->query("insert into usercredentials (uid, userid, username, userlastname, userpassword, useraccesskey) values(NULL, '".$username."', '".$name."', '".$lastname."', '".$encrypted_password_tmp."', '".$encrypted_administration_key_tmp."')");
    if (!$try_to_register_credentials_result) {
      printf("failed to register credentials : [fetal]");
      exit();
    }
    $try_to_register_basicdata_result = $connect->query("insert into userbasicdata (did, primaryaddress, secondaryaddress, city, state, province, postnum, phonenumber, emailaddress, extra) values(NULL, '".$primary_address."', '".$secondary_address."', '".$city."', '".$state."', '".$provice."', '".$postcode."', '".$phonenumber."', '".$emailaddress."', null)");
    if (!$try_to_register_basicdata_result) {
      printf("failed to register basic data : [fetal]");
      exit();
    }
    return ['username_valid' => true,
            'password_valid' => true,
            'email_valid' => true
    ];
  }
?>
