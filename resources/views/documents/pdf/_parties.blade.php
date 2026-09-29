{{--
    The handover parties, shared by every document type.

    Included rather than repeated in each template: these two blocks were
    validated in the form and then dropped, and copying a third and fourth
    version of a table that drifted from the other three is how that happened in
    the first place. Anything genuinely type-specific stays in the type's own
    template.

    Skips itself when there is nothing to say, because an empty "Penghantar"
    heading reads as a document that failed to fill in.
--}}
@php
    $parties = $document['parties'] ?? [];
    $sender = array_filter((array) ($parties['sender'] ?? []));
    $receiver = array_filter((array) ($parties['receiver'] ?? []));
@endphp

@if($sender || $receiver)
<table>
    <tr><th colspan="2">Para Penghantar &amp; Penerima</th></tr>

    @foreach([
        'Penghantar' => $sender,
        'Penerima' => $receiver,
    ] as $role => $party)
        @if($party)
            <tr>
                <td class="label">{{ $role }}</td>
                <td>
                    @foreach($party as $field => $value)
                        <div>
                            <span class="field">{{ ucwords(str_replace('_', ' ', $field)) }}:</span>
                            <span class="field-value">{{ $value }}</span>
                        </div>
                    @endforeach
                </td>
            </tr>
        @endif
    @endforeach
</table>
@endif
