<?php

use Illuminate\Support\Facades\Auth;

function sudahLogin()
{
    return Auth::check();
}

function getAuthUser()
{
    if (sudahLogin() == false) {
        return false;
    }

    return Auth::user();
}
