<?php

namespace App\Http\Livewire;

use App\Models\Customer;
use App\Support\Rut;
use Livewire\Component;

class PaymentAlert extends Component
{
    /** @var string Customer ID or RUT typed by the user. */
    public $search = '';

    /** @var \App\Models\Customer|null */
    public $customer = null;

    /** @var bool True after a lookup that found no customer. */
    public $notFound = false;

    protected $rules = [
        'search' => 'required|string|max:20',
    ];

    protected $messages = [
        'search.required' => 'Ingrese un RUT o ID de cliente.',
    ];

    public function lookup(): void
    {
        $this->validate();

        $term = trim($this->search);

        // Anything that is not a short numeric ID is treated as a RUT
        // and must have a valid check digit before hitting the database.
        if (!(ctype_digit($term) && strlen($term) <= 6) && !Rut::isValid($term)) {
            $this->customer = null;
            $this->notFound = false;
            $this->addError('search', 'El RUT ingresado no es válido.');

            return;
        }

        $this->customer = Customer::byRutOrId($term)->first();
        $this->notFound = $this->customer === null;
    }

    public function updatedSearch(): void
    {
        $this->customer = null;
        $this->notFound = false;
        $this->resetErrorBag();
    }

    public function render()
    {
        return view('livewire.payment-alert');
    }
}
