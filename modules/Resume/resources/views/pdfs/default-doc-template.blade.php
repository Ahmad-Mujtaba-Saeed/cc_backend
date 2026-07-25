<!DOCTYPE html>
<html>
<body style="font-family: Arial; font-size: 13px;">
<div>    
<h2>
        {{ htmlspecialchars($resumeData['candidateName'][0]['firstName'] ?? '') }}
        {{ htmlspecialchars($resumeData['candidateName'][0]['familyName'] ?? '') }}
    </h2>

    @if(!empty($resumeData['headline']))
    <p><i>{{ htmlspecialchars($resumeData['headline'] ?? '') }}</i></p>
    @endif

    @if(!empty($resumeData['profilePic']))
    <p><img src="{{ htmlspecialchars($resumeData['profilePic']) }}" width="80"></p>
    @endif

    @if(empty($resumeData['personalDisabled'] ?? null))
    <h3>{{ htmlspecialchars($resumeData['personalTitle'] ?? 'Personal Details') }}</h3>
    <table>
        <tr><td>Name:</td><td>
            {{ htmlspecialchars(($resumeData['candidateName'][0]['firstName'] ?? '') . ' ' . ($resumeData['candidateName'][0]['familyName'] ?? '')) }}
        </td></tr>

        @if(!empty($resumeData['email'][0]))
        <tr><td>Email:</td><td>{{ htmlspecialchars($resumeData['email'][0]) }}</td></tr>
        @endif

        @if(!empty($resumeData['phoneNumber'][0]['formattedNumber']))
        <tr><td>Phone:</td><td>{{ htmlspecialchars($resumeData['phoneNumber'][0]['formattedNumber']) }}</td></tr>
        @endif

        @if(!empty($resumeData['location']['formatted']))
        <tr><td>Address:</td><td>{{ htmlspecialchars($resumeData['location']['formatted']) }}</td></tr>
        @endif
    </table>
    @endif

    @if(!empty($resumeData['summary']['paragraph']))
    <h3>Profile</h3>
    <p>{{ htmlspecialchars($resumeData['summary']['paragraph']) }}</p>
    @endif

    @if(!empty($resumeData['workExperience']) && empty($resumeData['employmentDisabled'] ?? null))
    <h3>{{ htmlspecialchars($resumeData['employmentTitle'] ?? 'Employment') }}</h3>
    @foreach($resumeData['workExperience'] as $job)
        <p>
            <b>{{ htmlspecialchars($job['workExperienceJobTitle'] ?? '') }}</b><br>
            {{ htmlspecialchars($job['workExperienceOrganization'] ?? '') }}<br>
            {{ htmlspecialchars(($job['workExperienceDates']['start']['date'] ?? '') . ' - ' . ($job['workExperienceDates']['end']['date'] ?? 'Present')) }}
        </p>
        @if(!empty($job['highlights']['items']))
        <ul>
            @foreach($job['highlights']['items'] as $point)
                <li>{{ htmlspecialchars($point['bullet'] ?? '') }}</li>
            @endforeach
        </ul>
        @endif
    @endforeach
    @endif

</div>

</body>
</html>
