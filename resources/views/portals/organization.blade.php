<x-portal-landing
    :portal="\App\Enums\Portal::Organization"
    :eyebrow="__('Service Request Portal')"
    :title="__('Request engineering services and verified technical teams')"
    :intro="__('For telecom operators, contractors, government institutions and private companies. Describe your project, receive a quotation and follow delivery — all in one place.')"
    :register-label="__('Register your organisation')"
    :steps="[
        [__('Register your organisation'), __('Create an account for your organisation and verify your email address.')],
        [__('Submit a service request'), __('Describe the work, location, schedule and the people and qualifications you need.')],
        [__('Approve and track'), __('Review the quotation, approve it online and follow the assigned team through to completion.')],
    ]"
    :features="[
        ['heroicon-o-signal', __('Telecom, power & fibre'), __('Tower works, transmission, generators, earthing, networking, transport and manpower supply.')],
        ['heroicon-o-shield-check', __('Cleared personnel'), __('Teams are drawn from applicants with verified qualifications and valid police clearance.')],
        ['heroicon-o-chat-bubble-left-right', __('Direct communication'), __('Message the WaveTeam project team and download documents from your dashboard.')],
    ]"
/>
