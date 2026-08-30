<?php

declare(strict_types=1);

/**
 * English UI strings.
 *
 * Placeholder: the product ships in Spanish first. This file exists so the
 * scaffolding is exercised in both locales from day one — retrofitting i18n is
 * what costs, not having it.
 *
 * Note: this is UI text only, and UI text does translate cleanly. The core
 * variable catalogue does NOT — see docs/design/04-arquitectura.md §4.bis. When
 * English becomes real, its catalogue gets designed, not translated.
 */
return [
    'app' => [
        'name'    => 'MaiMind',
        'tagline' => 'Observe your life. Let the patterns show up on their own.',
    ],

    'capture' => [
        'greeting'       => 'How are you?',
        'record'         => 'Record',
        'stop'           => 'Stop',
        'saving'         => 'Saving…',
        'saved'          => 'Saved',
        'mood_hint'      => 'How are you doing right now?',
        'mood_skip'      => 'Rather not say',
        'last_entry'     => 'Last entry',
        'queued'         => 'No connection. Saved here; it will send itself.',
        'pending_one'    => '1 recording waiting to be sent',
        'pending_many'   => ':count recordings waiting to be sent',
        'sending_queue'  => 'Sending what was left pending…',
        'session_gone'   => 'Sign in again and what is pending will be sent.',
        'recording'     => 'Recording',
        'cancel'        => 'Discard',
        'no_entries'    => 'You have not recorded anything yet',
        'retry'         => 'Retry',
        'today'         => 'today',
        'yesterday'     => 'yesterday',
    ],

    'list' => [
        'title'          => 'Your recordings',
        'count'          => ':count in total',
        'back_to_record' => 'Record',
        'see_all'        => 'See all',
    ],

    'entry' => [
        'back'            => 'Back',
        'audio'           => 'The recording',
        'audio_expires'   => 'Deleted :days days after recording.',
        'transcript'      => 'What you said',
        'edit_hint'       => 'If the transcriber got it wrong, fix it here.',
        'save'            => 'Save correction',
        'saved'           => 'Correction saved',
        'edited_by_you'   => 'Corrected by you',
        'machine_said'    => 'Transcribed by :model',
        'words'           => ':count words',
        'not_yet'         => 'Not transcribed yet',
        'in_progress'     => 'Transcribing…',
        'failed'          => 'Could not be transcribed',
        'audio_gone'      => 'The audio is gone: it is deleted after :days days',
        'gap_notice'      => ':seconds s of audio were not transcribed',
        'gap_explain'     => 'The transcriber skipped that stretch. You can write it in if you remember it.',
        'mood_was'        => 'Before recording you marked :value out of 5',
    ],

    'review' => [
        'title'             => 'Here is what I understood',
        'confirm'           => 'Yes, that is right',
        'edit'              => 'Fix it',
        'reject'            => 'No, not that',
        'skip'              => 'Not now',
        'pending'           => '{count} things to review',
        'revision_question' => 'Were you wrong, or do you see it differently now?',
        'was_wrong'         => 'I was wrong',
        'see_differently'   => 'I see it differently now',
        'new_variable'      => 'Track ":name"? You have mentioned it :count times',
        'track_it'          => 'Track it',
        'ignore_it'         => 'Ignore',
    ],

    'evidence' => [
        'said_by_you'    => 'You said this',
        'inferred'       => 'Inferred, may be wrong',
        'confirmed'      => 'Confirmed by you',
        'as_experienced' => 'How you lived it',
        'as_understood'  => 'How you see it now',
    ],

    'analysis' => [
        'associated_with' => 'appears associated with',
        'precedes'        => 'often precedes',
        'compatible_with' => 'the data are compatible with',
        'your_claim'      => 'what you tell',
        'observed'        => 'what the data show',
        'insufficient'    => 'Not enough data yet',
        'need_more'       => ':count more days needed to look at this',
        'no_data_gap'     => 'No entries',
        'baseline'        => 'your usual baseline',
    ],


    'auth' => [
        'sign_in'      => 'Sign in',
        'sign_up'      => 'Create account',
        'sign_out'     => 'Sign out',
        'email'        => 'Email',
        'password'     => 'Password',
        'display_name' => 'What should I call you',
        'min_chars'    => 'At least 10 characters',
        'no_account'   => 'No account yet?',
        'have_account' => 'Already have an account?',

        'invalid_credentials' => 'That email or password is not right',
        'invalid_email'       => 'That email does not look valid',
        'password_too_short'  => 'The password needs at least 10 characters',
        'password_is_email'   => 'The password cannot be your own email',
        'email_taken'         => 'There is already an account with that email',
        'account_inactive'    => 'This account is not active',
        'too_many_attempts'   => 'Too many attempts. Try again in :minutes minutes',
    ],

    'install' => [
        'title'      => 'Put it on your home screen',
        'why'        => 'It opens full screen in one tap, without going through the browser.',
        'ios_steps'  => 'Tap Share, at the bottom, then “Add to Home Screen”.',
        'action'     => 'Install',
        'dismiss'    => 'Not now',
    ],

    'offline' => [
        'title'      => 'No connection',
        'body'       => 'I could not load the app. Try again when you are back online.',
        'queue_safe' => 'Anything you recorded is still stored on this device and will send itself.',
    ],

    'errors' => [
        'generic'        => 'Something went wrong. Try again.',
        'csrf'           => 'The form expired. Please try again.',
        'not_found'      => 'Not found',
        'unauthorized'   => 'You need to sign in',
        'audio_too_big'  => 'That recording is too long',
        'audio_bad_type' => 'That audio format is not supported',
        'audio_missing'  => 'The recording did not arrive',
        'audio_empty'    => 'The recording came out empty',
        'queue_failed'   => 'I could not store the recording on this device.',
        'mic_denied'     => 'I need permission to use the microphone',
        'mic_missing'    => 'I cannot find a microphone',
        'insecure'       => 'Recording needs a secure connection (https)',
    ],
];
