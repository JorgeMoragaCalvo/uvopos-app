<div>
    <ul class="nav nav-tabs mb-3">
        @foreach ([
            \App\Http\Livewire\BankReconciliation::TAB_MOVEMENTS => 'Movimientos',
            \App\Http\Livewire\BankReconciliation::TAB_IMPORT => 'Importar cartola',
            \App\Http\Livewire\BankReconciliation::TAB_SETTLEMENTS => 'Liquidaciones Transbank',
        ] as $tabKey => $tabLabel)
            <li class="nav-item">
                <a class="nav-link {{ $tab === $tabKey ? 'active' : '' }}"
                   href="#"
                   wire:click.prevent="selectTab('{{ $tabKey }}')">
                    {{ $tabLabel }}
                    @if ($tabKey === \App\Http\Livewire\BankReconciliation::TAB_MOVEMENTS && $counts['suggested'] + $counts['unmatched'] > 0)
                        <span class="badge badge-primary">{{ $counts['suggested'] + $counts['unmatched'] }}</span>
                    @endif
                </a>
            </li>
        @endforeach
    </ul>

    @if ($importMessage)
        <div class="alert alert-success" role="alert">{{ $importMessage }}</div>
    @endif

    {{-- ------------------------------------------------------------- --}}
    {{-- Importar cartola                                              --}}
    {{-- ------------------------------------------------------------- --}}
    @if ($tab === \App\Http\Livewire\BankReconciliation::TAB_IMPORT)
        <div class="card">
            <div class="card-header"><strong>Importar cartola</strong></div>
            <div class="card-body">
                <p class="text-muted">
                    Descargue la cartola desde el portal de su banco y guárdela como
                    <strong>CSV</strong> (en Excel: Archivo → Guardar como → CSV). Los
                    movimientos ya importados no se duplican.
                </p>

                <form wire:submit.prevent="import">
                    <div class="form-group">
                        <label for="recon-bank">Banco</label>
                        <select id="recon-bank" class="form-control" wire:model="bank">
                            @foreach ($banks as $bankKey => $bankConfig)
                                <option value="{{ $bankKey }}">{{ $bankConfig['label'] }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="recon-file">Archivo de la cartola</label>
                        <input type="file"
                               id="recon-file"
                               class="form-control-file @error('cartola') is-invalid @enderror"
                               wire:model="cartola">
                        @error('cartola')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                        <div wire:loading wire:target="cartola" class="text-muted small mt-1">Subiendo archivo…</div>
                    </div>

                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="import">
                        <span wire:loading.remove wire:target="import">Importar</span>
                        <span wire:loading wire:target="import">Importando…</span>
                    </button>
                </form>
            </div>
        </div>
    @endif

    {{-- ------------------------------------------------------------- --}}
    {{-- Movimientos por conciliar                                     --}}
    {{-- ------------------------------------------------------------- --}}
    @if ($tab === \App\Http\Livewire\BankReconciliation::TAB_MOVEMENTS)
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong>Movimientos</strong>
                <select class="form-control form-control-sm w-auto" wire:model="statusFilter">
                    <option value="">Pendientes ({{ $counts['suggested'] + $counts['unmatched'] }})</option>
                    <option value="suggested">Con sugerencia ({{ $counts['suggested'] }})</option>
                    <option value="unmatched">Sin sugerencia ({{ $counts['unmatched'] }})</option>
                    <option value="matched">Conciliados ({{ $counts['matched'] }})</option>
                    <option value="ignored">Ignorados ({{ $counts['ignored'] }})</option>
                </select>
            </div>
            <div class="card-body">
                @if ($movements->isEmpty())
                    <div class="alert alert-secondary mb-0" role="alert">
                        No hay movimientos en este estado. Importe una cartola para comenzar.
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Descripción</th>
                                    <th class="text-right">Monto</th>
                                    <th>Sugerencia</th>
                                    <th class="text-right">Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($movements as $movement)
                                    <tr wire:key="movement-{{ $movement->id }}"
                                        class="{{ $movement->direction === 'debit' ? 'text-muted' : '' }}">
                                        <td class="text-nowrap">{{ $movement->posted_at->format('d-m-Y') }}</td>
                                        <td>
                                            {{ $movement->description }}
                                            @if ($movement->reference)
                                                <br><small class="text-muted">Ref. {{ $movement->reference }}</small>
                                            @endif
                                        </td>
                                        <td class="text-right text-nowrap">
                                            {{ $movement->direction === 'debit' ? '-' : '' }}${{ number_format($movement->amount_clp, 0, ',', '.') }}
                                        </td>
                                        <td>
                                            @if ($movement->customer)
                                                <strong>{{ $movement->customer->name }}</strong><br>
                                                <small class="text-muted">
                                                    {{ $movement->customer->formatted_rut }}
                                                    @if ($movement->match_confidence)
                                                        — {{ $movement->match_confidence }}%
                                                    @endif
                                                </small>
                                            @elseif ($movement->match_reason)
                                                <span class="badge badge-info">{{ $movement->match_reason }}</span>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif

                                            @if ($movement->customer && $movement->match_reason)
                                                <br><small class="text-muted">{{ $movement->match_reason }}</small>
                                            @endif
                                        </td>
                                        <td class="text-right text-nowrap">
                                            @if ($movement->is_resolved)
                                                <span class="badge {{ $movement->status === 'matched' ? 'badge-success' : 'badge-secondary' }}">
                                                    {{ $movement->status === 'matched' ? 'Conciliado' : 'Ignorado' }}
                                                </span>
                                                @if ($movement->suscriptor_payment_id)
                                                    <br><small class="text-muted">Pago #{{ $movement->suscriptor_payment_id }}</small>
                                                @else
                                                    {{-- Nothing was recorded, so this decision can be taken back. --}}
                                                    <br><button type="button" class="btn btn-sm btn-link p-0"
                                                                wire:click="returnToQueue({{ $movement->id }})">
                                                        Devolver a la lista
                                                    </button>
                                                @endif
                                            @elseif ($movement->direction === 'debit')
                                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                                        wire:click="ignoreMovement({{ $movement->id }})">
                                                    Ignorar
                                                </button>
                                            @else
                                                @if ($movement->empresa_id)
                                                    <button type="button" class="btn btn-sm btn-success"
                                                            wire:click="startConfirm({{ $movement->id }})">
                                                        Conciliar
                                                    </button>
                                                @endif
                                                <button type="button" class="btn btn-sm btn-outline-primary"
                                                        wire:click="startAssign({{ $movement->id }})">
                                                    Asignar…
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                                        wire:click="ignoreMovement({{ $movement->id }})">
                                                    Ignorar
                                                </button>
                                            @endif
                                        </td>
                                    </tr>

                                    @if ($confirmation && $confirmation['movement']->id === $movement->id)
                                        <tr wire:key="confirm-{{ $movement->id }}" class="bg-light">
                                            <td colspan="5">
                                                <div class="d-flex flex-wrap align-items-center">
                                                    <span class="mr-3">
                                                        ¿Registrar ${{ number_format($movement->amount_clp, 0, ',', '.') }}
                                                        como pago de <strong>{{ $confirmation['customer']->name }}</strong>?
                                                    </span>
                                                    <button type="button" class="btn btn-sm btn-success mr-2"
                                                            wire:click="confirmMatch({{ $movement->id }})">
                                                        Sí, conciliar
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary"
                                                            wire:click="cancelConfirm">
                                                        Cancelar
                                                    </button>
                                                </div>

                                                @if ($confirmation['shortfall'] > 0)
                                                    {{-- The period is a full one whatever the amount, so a short
                                                         transfer has to be a visible choice, not a side effect. --}}
                                                    <div class="alert alert-warning mt-2 mb-0 py-2">
                                                        Depósito ${{ number_format($movement->amount_clp, 0, ',', '.') }},
                                                        cargo del plan ${{ number_format($confirmation['customer']->charge_amount, 0, ',', '.') }}
                                                        (faltan ${{ number_format($confirmation['shortfall'], 0, ',', '.') }}).
                                                        Al conciliar se renueva el período completo.
                                                    </div>
                                                @endif
                                            </td>
                                        </tr>
                                    @endif

                                    @if ($assigningMovementId === $movement->id)
                                        <tr wire:key="assign-{{ $movement->id }}" class="bg-light">
                                            <td colspan="5">
                                                <form wire:submit.prevent="assignMovement" class="form-inline">
                                                    <label class="mr-2" for="assign-{{ $movement->id }}">
                                                        Asignar a RUT o ID:
                                                    </label>
                                                    <input type="text"
                                                           id="assign-{{ $movement->id }}"
                                                           class="form-control form-control-sm mr-2 @error('assignSearch') is-invalid @enderror"
                                                           placeholder="Ej: 12.345.678-5 o 42"
                                                           wire:model.defer="assignSearch">
                                                    <button type="submit" class="btn btn-sm btn-primary mr-2">
                                                        Continuar
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary"
                                                            wire:click="cancelAssign">
                                                        Cancelar
                                                    </button>
                                                    @error('assignSearch')
                                                        <div class="invalid-feedback d-block w-100">{{ $message }}</div>
                                                    @enderror
                                                </form>
                                            </td>
                                        </tr>
                                    @endif
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    {{ $movements->links() }}
                @endif
            </div>
        </div>
    @endif

    {{-- ------------------------------------------------------------- --}}
    {{-- Liquidaciones Transbank                                       --}}
    {{-- ------------------------------------------------------------- --}}
    @if ($tab === \App\Http\Livewire\BankReconciliation::TAB_SETTLEMENTS)
        <div class="card">
            <div class="card-header"><strong>Liquidaciones Transbank</strong></div>
            <div class="card-body">
                <p class="text-muted">
                    Cada depósito de Transbank se compara con la suma de los pagos Webpay
                    registrados {{ config('bank_reconciliation.settlement_lag_days') }} día(s)
                    antes. Una diferencia significa que un cargo no se liquidó, o que hubo una
                    devolución fuera de la aplicación.
                </p>

                @if (empty($settlements))
                    <div class="alert alert-secondary mb-0" role="alert">
                        No se han importado depósitos de Transbank.
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Depósito</th>
                                    <th>Cargos del</th>
                                    <th class="text-right">Cobrado</th>
                                    <th class="text-right">Depositado</th>
                                    <th class="text-right">Diferencia</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($settlements as $settlement)
                                    <tr wire:key="settlement-{{ $settlement['movement']->id }}"
                                        class="{{ $settlement['matches'] ? '' : 'table-warning' }}">
                                        <td class="text-nowrap">{{ $settlement['movement']->posted_at->format('d-m-Y') }}</td>
                                        <td class="text-nowrap">
                                            {{ \Carbon\Carbon::parse($settlement['charge_date'])->format('d-m-Y') }}
                                            <small class="text-muted">({{ $settlement['payments']->count() }} pagos)</small>
                                        </td>
                                        <td class="text-right">${{ number_format($settlement['charged'], 0, ',', '.') }}</td>
                                        <td class="text-right">${{ number_format($settlement['deposited'], 0, ',', '.') }}</td>
                                        <td class="text-right">
                                            @if ($settlement['matches'])
                                                <span class="badge badge-success">Cuadra</span>
                                            @else
                                                <strong>${{ number_format($settlement['difference'], 0, ',', '.') }}</strong>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
