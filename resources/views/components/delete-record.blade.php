@props(['action', 'label', 'hint'])
<section id="suppression" class="editor-form">
    <h2>Supprimer {{ $label }}</h2>
    <p>{{ $hint }}</p>
    @error('deletion')<p class="field-error" role="alert">{{ $message }}</p>@enderror
    <form method="POST" action="{{ $action }}">
        @csrf
        @method('DELETE')
        <label class="checkbox-label"><input type="checkbox" name="confirmation" value="1" required> Je confirme la suppression définitive.</label>
        @error('confirmation')<p class="field-error" role="alert">{{ $message }}</p>@enderror
        <div class="form-actions"><button class="secondary-button" type="submit">Supprimer {{ $label }}</button></div>
    </form>
</section>
