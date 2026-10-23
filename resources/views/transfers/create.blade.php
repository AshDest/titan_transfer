<x-layouts.workspace title="Nouvelle opération">
    <x-page-heading title="Nouvelle opération" />
    @if($errors->any())<div class="notice page-notice" role="alert"><div><strong>Vérifiez les informations suivantes :</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div></div>@endif
    <form method="POST" action="{{ route('transfers.preview') }}" class="editor-form transfer-form" data-transfer-form data-people-url="{{ route('transfers.people') }}">
        @csrf
        <section class="transfer-section">
            <h2><span>1</span> Trajet du transfert</h2>
            <div class="form-grid">
                <x-field name="source_branch_id" label="Agence de départ" required>
                    <option value="">Choisir l’agence</option>
                    @foreach($sources as $branch)<option value="{{ $branch->id }}" data-currency="{{ $branch->default_currency }}" @selected(old('source_branch_id', auth()->user()->branch_id ?? ($sources->count() === 1 ? $sources->first()->id : null)) == $branch->id)>{{ $branch->name }} · {{ $branch->default_currency }}</option>@endforeach
                </x-field>
                <x-field name="destination_branch_id" label="Agence de retrait" required>
                    <option value="">Choisir la destination</option>
                    @foreach($destinations as $branch)<option value="{{ $branch->id }}" @selected(old('destination_branch_id') == $branch->id)>{{ $branch->name }} · {{ $branch->default_currency }}</option>@endforeach
                </x-field>
            </div>
        </section>
        @foreach(['sender' => ['2', 'Client expéditeur', 'Client existant', 'Nouveau client'], 'beneficiary' => ['3', 'Bénéficiaire', 'Bénéficiaire existant', 'Nouveau bénéficiaire']] as $party => $labels)
            @php($canCreate = auth()->user()->can($party === 'sender' ? 'customers.create' : 'beneficiaries.create'))
            @php($mode = old($party.'_mode', $canCreate ? 'new' : 'existing'))
            <section class="transfer-section" data-party="{{ $party }}">
                <h2><span>{{ $labels[0] }}</span> {{ $labels[1] }}</h2>
                <div class="party-modes" role="group" aria-label="{{ $labels[1] }}">
                    <label><input type="radio" name="{{ $party }}_mode" value="existing" @checked($mode === 'existing')> {{ $labels[2] }}</label>
                    @if($canCreate)<label><input type="radio" name="{{ $party }}_mode" value="new" @checked($mode === 'new')> <x-icon name="plus" /> {{ $labels[3] }}</label>@endif
                </div>
                <fieldset data-party-existing @disabled($mode !== 'existing') @if($mode !== 'existing') hidden @endif>
                    <legend class="sr-only">{{ $labels[2] }}</legend>
                    <label class="search-label" for="{{ $party }}-search">Rechercher par nom ou téléphone</label>
                    <input type="search" id="{{ $party }}-search" class="form-control" data-person-search placeholder="Saisir un nom ou un téléphone…" maxlength="100" autocomplete="off">
                    <p class="field-hint" data-search-status role="status"></p>
                    <x-field :name="$party.'_id'" label="Sélectionner dans les résultats" required>
                        <option value="">Choisir une personne</option>
                        @if($selected[$party])<option value="{{ $selected[$party]->id }}" selected>{{ $selected[$party]->first_name }} {{ $selected[$party]->last_name }} · {{ $selected[$party]->phone }}</option>@endif
                    </x-field>
                </fieldset>
                @if($canCreate)
                    <fieldset data-party-new @disabled($mode !== 'new') @if($mode !== 'new') hidden @endif>
                        <legend class="sr-only">{{ $labels[3] }}</legend>
                        <div class="form-grid">
                            <x-field :name="$party.'.first_name'" label="Prénom" required maxlength="100" />
                            <x-field :name="$party.'.last_name'" label="Nom" maxlength="100" />
                            <x-field :name="$party.'.phone'" label="Téléphone" type="tel" required maxlength="32" placeholder="+243… / +971…" />
                            <x-field :name="$party.'.country'" label="Pays" required maxlength="100" />
                        </div>
                        <details class="party-details" @if($errors->has($party.'.id_type') || $errors->has($party.'.id_number')) open @endif><summary>Informations complémentaires</summary><div class="form-grid">
                            <x-field :name="$party.'.city'" label="Ville" maxlength="100" />
                            <x-field :name="$party.'.address'" label="Adresse" maxlength="500" />
                            <x-field :name="$party.'.id_type'" label="Type de pièce d’identité" maxlength="50" />
                            <x-field :name="$party.'.id_number'" label="Numéro de pièce d’identité" maxlength="100" />
                        </div></details>
                    </fieldset>
                @endif
            </section>
        @endforeach
        <section class="transfer-section">
            <h2><span>4</span> Montant de l’opération</h2>
            <div class="form-grid">
                <x-field name="amount_received" label="Montant total à encaisser" required inputmode="decimal" pattern="[0-9]+([.,][0-9]{1,2})?" placeholder="Ex. 100.00" hint="Frais inclus." />
                <div class="transfer-currency"><span>Devise d’encaissement</span><strong data-source-currency>—</strong></div>
            </div>
            <x-field name="notes" label="Note (facultatif)" maxlength="1000" />
        </section>
        <div class="form-actions"><a class="secondary-button" href="{{ route('transfers.index') }}">Annuler</a><button class="primary-button compact-button">Vérifier l’opération <x-icon name="arrow" /></button></div>
    </form>
</x-layouts.workspace>
