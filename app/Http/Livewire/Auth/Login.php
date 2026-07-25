<?php

namespace App\Http\Livewire\Auth;

use App\Providers\RouteServiceProvider;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Minimal email/password login for the customer portal (/mi-cuenta).
 * Deliberately no registration or password reset — accounts come from
 * the production `users` table, not from self sign-up.
 *
 * Suspended users (users.status = 0) are still allowed in: locking them
 * out would hide the payment button from exactly the customers who need
 * it to get reactivated.
 */
class Login extends Component
{
    public $email = '';

    public $password = '';

    public $remember = false;

    protected $rules = [
        'email' => 'required|email',
        'password' => 'required|string',
    ];

    protected $messages = [
        'email.required' => 'Ingrese su correo electrónico.',
        'email.email' => 'Ingrese un correo electrónico válido.',
        'password.required' => 'Ingrese su contraseña.',
    ];

    public function authenticate()
    {
        $this->validate();

        if (!Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            $this->addError('email', 'Credenciales inválidas.');

            return;
        }

        session()->regenerate();

        return redirect()->intended(
            Auth::user()->isAdmin() ? route('payment-alert') : RouteServiceProvider::HOME
        );
    }

    public function render()
    {
        return view('livewire.auth.login');
    }
}
