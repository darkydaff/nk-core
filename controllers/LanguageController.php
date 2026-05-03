<?php

class LanguageController {
    public function change() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $lang = $_POST['language'] ?? '';
            
            if (Translator::setLanguage($lang)) {
                $_SESSION['success'] = 'Language changed successfully';
            } else {
                $_SESSION['error'] = 'Invalid language';
            }
            
            $redirect = $_POST['redirect'] ?? '/dashboard';
            redirect($redirect);
        } else {
            redirect('/dashboard');
        }
    }
}
