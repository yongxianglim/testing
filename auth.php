<?php
session_start();

function requireLogin()
{
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit;
    }
}

function requireRole($roles)
{
    requireLogin();
    if (!in_array($_SESSION['role'], (array)$roles)) {
        http_response_code(403);
        exit("Access denied.");
    }
}

function currentRole()
{
    return $_SESSION['role'] ?? '';
}

function currentUser()
{
    return $_SESSION['username'] ?? '';
}
