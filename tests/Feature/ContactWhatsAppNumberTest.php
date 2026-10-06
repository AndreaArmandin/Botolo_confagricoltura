<?php

use App\Models\Contact;

function phone(?string $phone): ?string
{
    return Contact::factory()->make(['phone' => $phone])->whatsAppNumber();
}

it('strips the plus and the spaces of an already prefixed number', function () {
    expect(phone('+39 333 1234567'))->toBe('393331234567');
});

it('adds the country code to a bare italian mobile', function () {
    expect(phone('333 1234567'))->toBe('393331234567');
});

it('turns a handwritten 0039 prefix into 39', function () {
    expect(phone('0039 333 1234567'))->toBe('393331234567');
});

it('ignores dashes, dots and brackets', function () {
    expect(phone('(+39) 333-123.4567'))->toBe('393331234567');
});

it('does not mistake a 393 mobile for an already prefixed number', function () {
    // 393… is a real Italian mobile range, so ten digits must win over the prefix test.
    expect(phone('3931234567'))->toBe('393931234567');
});

it('drops the leading zero of a landline before prefixing it', function () {
    expect(phone('0171 123456'))->toBe('390171123456')->not->toBe('39171123456');
});

it('returns null when there is no usable phone', function (?string $phone) {
    expect(phone($phone))->toBeNull();
})->with([null, '', '   ', 'non lo so']);
