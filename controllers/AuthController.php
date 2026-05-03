<?php

class AuthController
{
    public function index()
    {
        if (!Auth::check()) {
            redirect('/login');
        }
        redirect('/dashboard');
    }

    public function showLogin()
    {
        if (Auth::check()) {
            redirect('/dashboard');
        }
        View::render('login.twig');
    }

    public function login()
    {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (Auth::login($email, $password)) {
            redirect('/dashboard');
        }

        View::render('login.twig', ['error' => Translator::t('auth.invalid_credentials')]);
    }

    public function showRegister()
    {
        if (Auth::check()) {
            redirect('/dashboard');
        }
        View::render('register.twig');
    }

    public function register()
    {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            View::render('register.twig', ['error' => Translator::t('auth.invalid_email')]);
            return;
        }

        if (strlen($password) < 6) {
            View::render('register.twig', ['error' => Translator::t('auth.password_min_length')]);
            return;
        }

        try {
            $success = Auth::register($name, $email, $password);
            if ($success) {
                Auth::login($email, $password);
                redirect('/dashboard');
            }
        } catch (Throwable $e) {
            // Email already exists or other error
        }

        View::render('register.twig', ['error' => Translator::t('auth.registration_failed')]);
    }

    public function logout()
    {
        Auth::logout();
        redirect('/login');
    }
}
