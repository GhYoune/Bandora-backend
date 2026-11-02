<?php

// Sample vocabulary decks (common General Training topics). Mirrored in frontend/src/mocks/data/decks.ts.
return [
    [
        'title' => 'Work and jobs',
        'description' => 'Words for letters to employers and essays about work.',
        'topic' => 'Work',
        'is_free' => true,
        'cards' => [
            ['applicant', 'A person who formally asks for a job or a place.', 'There were over fifty applicants for the position.', 'candidat', 'متقدّم'],
            ['colleague', 'A person you work with.', 'I asked a colleague to cover my shift on Friday.', 'collègue', 'زميل'],
            ['deadline', 'The latest time by which something must be done.', 'The deadline for the report is next Monday.', 'date limite', 'موعد نهائي'],
            ['flexible hours', 'A system where workers can choose when they start and finish.', 'Flexible hours help parents balance work and family.', 'horaires flexibles', 'ساعات عمل مرنة'],
            ['promotion', 'A move to a more important job in the same company.', 'She received a promotion after two years.', 'promotion', 'ترقية'],
            ['resign', 'To leave a job by choice.', 'He decided to resign and start his own business.', 'démissionner', 'يستقيل'],
            ['workload', 'The amount of work a person has to do.', 'My workload has doubled since the new project started.', 'charge de travail', 'عبء العمل'],
            ['vacancy', 'A job that is available for someone to do.', 'I am writing to apply for the vacancy advertised on your website.', 'poste vacant', 'وظيفة شاغرة'],
        ],
    ],
    [
        'title' => 'Housing and accommodation',
        'description' => 'For complaint letters to landlords and questions about where you live.',
        'topic' => 'Housing',
        'is_free' => false,
        'cards' => [
            ['landlord', 'A person who rents a house or flat to someone.', 'I have contacted my landlord about the broken heating several times.', 'propriétaire', 'المالك'],
            ['tenant', 'A person who pays rent to live in a place.', 'Tenants must give one month’s notice before leaving.', 'locataire', 'مستأجر'],
            ['deposit', 'Money paid at the start of a rental and returned at the end.', 'The deposit will be returned if there is no damage.', 'caution', 'تأمين'],
            ['utilities', 'Services such as electricity, gas and water.', 'The rent does not include utilities.', 'charges', 'المرافق'],
            ['furnished', 'Rented with furniture already in it.', 'I am looking for a furnished flat near the city centre.', 'meublé', 'مفروش'],
            ['neighbourhood', 'The area around where you live.', 'It is a quiet neighbourhood with good schools.', 'quartier', 'حيّ'],
            ['maintenance', 'Work done to keep a building in good condition.', 'Maintenance of the garden is the tenant’s responsibility.', 'entretien', 'صيانة'],
        ],
    ],
    [
        'title' => 'Linking words for essays',
        'description' => 'Connect your ideas clearly in Writing Task 2.',
        'topic' => 'Writing',
        'is_free' => false,
        'cards' => [
            ['furthermore', 'Used to add another point that supports the previous one.', 'Furthermore, public transport reduces air pollution.', 'de plus', 'علاوة على ذلك'],
            ['nevertheless', 'Despite what has just been said.', 'The plan is expensive. Nevertheless, it is worth trying.', 'néanmoins', 'ومع ذلك'],
            ['consequently', 'As a result.', 'Prices rose sharply; consequently, sales fell.', 'par conséquent', 'وبالتالي'],
            ['whereas', 'Used to compare two different facts.', 'Some people prefer cities, whereas others enjoy the countryside.', 'alors que', 'بينما'],
            ['in conclusion', 'Used to introduce the final summary of an essay.', 'In conclusion, both governments and individuals must act.', 'en conclusion', 'في الختام'],
            ['for instance', 'Used to give an example.', 'Many jobs, for instance in banking, can be done from home.', 'par exemple', 'على سبيل المثال'],
        ],
    ],
];
