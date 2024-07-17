<?php
    require 'aisQlib.user.php';
    $user = new \aisQlib\user;

    if(isset($_REQUEST['action']))
    {
        $request = $_REQUEST['action'];
    }else{
        exit('no request');
    }
    if(isset($request))
    {
        if($request == "login")
        {
            $username = $_REQUEST['username'];
            $password = $_REQUEST['password'];
            if(!isset($username) || !isset($password))
            {
                exit("Please provide username/password.");
            }else{
                if($user->logIn($username, $password))
                {
                    echo 'success';
                }else{
                    echo 'fail: wrong username or password';
                }
            }
        }else if($request == "logout")
        {
            if($user->isLoggedIn())
            {
                if($user->logOut())
                {
                    echo 'success';
                }
            }else{
                echo 'fail: not logged in';
            }
        }else if($request == "getusername")
        {
            if($user->isLoggedIn())
            {
                echo $user->getUsername();
            }else{
                echo 'fail: not logged in';
            }
        }else{
            echo 'fail: not logged in';
        }
    }