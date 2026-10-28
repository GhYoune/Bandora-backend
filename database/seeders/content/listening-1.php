<?php

/*
 * Sample Listening test in the teacher's authoring format (what the Filament form stores).
 * Original content written for the prototype. Audio paths starting with "/" are served by the
 * Next.js app's public folder (placeholder tones); real tests upload audio in Filament.
 */

return [
    'parts' => [
        [
            'audio' => '/mock/audio/part-1.mp3',
            'audio_duration' => 45,
            'groups' => [
                ['type' => 'gap_fill', 'data' => [
                    'instructions' => 'Complete the form below.',
                    'word_limit' => 'Write ONE WORD AND/OR A NUMBER for each answer.',
                    'layout' => 'form',
                    'title' => 'Riverside Leisure Centre – New Member Form',
                    'text' => implode("\n", [
                        'Name: Sarah [[Mitchell]]',
                        'Address: 14 [[Harbour|Harbor]] Road, Eastfield',
                        'Postcode: [[EF4 7TR|EF47TR]]',
                        'Date of birth: [[12 March 1994|12/03/1994|12.03.1994|March 12 1994|12th March 1994]]',
                        'Membership type: [[off-peak|off peak|offpeak]]',
                        'Main activity: [[swimming]]',
                        'Heard about the centre from: a local [[newspaper]]',
                    ]),
                ]],
                ['type' => 'multiple_choice', 'data' => [
                    'questions' => [
                        ['prompt' => 'What does the joining fee include?', 'options' => [
                            ['text' => 'a free towel'], ['text' => 'a fitness assessment', 'correct' => true], ['text' => 'a locker key'],
                        ]],
                        ['prompt' => 'When will Sarah have her induction?', 'options' => [
                            ['text' => 'Monday evening'], ['text' => 'Wednesday morning'], ['text' => 'Saturday afternoon', 'correct' => true],
                        ]],
                        ['prompt' => 'How will Sarah pay for her membership?', 'options' => [
                            ['text' => 'by card on the website'], ['text' => 'by monthly direct debit', 'correct' => true], ['text' => 'in cash at reception'],
                        ]],
                    ],
                ]],
            ],
        ],
        [
            'audio' => '/mock/audio/part-2.mp3',
            'audio_duration' => 45,
            'groups' => [
                ['type' => 'diagram_label', 'data' => [
                    'instructions' => 'Label the map below. Write the correct letter, A–H, next to Questions 11–15.',
                    'title' => 'Greenway Park',
                    'image' => '/mock/greenway-park-map.svg',
                    'image_alt' => 'Map of Greenway Park with the main gate at the bottom, a lake in the centre, and eight places marked A to H.',
                    'mode' => 'letters',
                    'letters' => 8,
                    'questions' => [
                        ['prompt' => 'Café', 'answer' => 'C'],
                        ['prompt' => "Children's playground", 'answer' => 'F'],
                        ['prompt' => 'Wildlife pond', 'answer' => 'A'],
                        ['prompt' => 'Bike hire', 'answer' => 'H'],
                        ['prompt' => 'Information centre', 'answer' => 'D'],
                    ],
                ]],
                ['type' => 'multiple_choice', 'data' => [
                    'instructions' => 'Choose TWO letters, A–E.',
                    'questions' => [
                        ['prompt' => 'Which TWO services are free for local residents?', 'options' => [
                            ['text' => 'guided nature walks', 'correct' => true], ['text' => 'car parking'], ['text' => 'bike hire'],
                            ['text' => 'gardening workshops', 'correct' => true], ['text' => 'boat rides on the lake'],
                        ]],
                    ],
                ]],
                ['type' => 'multiple_choice', 'data' => [
                    'questions' => [
                        ['prompt' => 'In winter, the park is open from', 'options' => [
                            ['text' => '7 am to 5 pm.'], ['text' => '8 am to 6 pm.', 'correct' => true], ['text' => '9 am to 4 pm.'],
                        ]],
                        ['prompt' => 'Dogs must be kept on a lead', 'options' => [
                            ['text' => 'everywhere in the park.'], ['text' => 'near the wildlife pond.', 'correct' => true], ['text' => 'in the north field only.'],
                        ]],
                        ['prompt' => 'The park mainly needs volunteers to', 'options' => [
                            ['text' => 'plant trees.'], ['text' => 'work in the café.'], ['text' => 'help at public events.', 'correct' => true],
                        ]],
                    ],
                ]],
            ],
        ],
        [
            'audio' => '/mock/audio/part-3.mp3',
            'audio_duration' => 45,
            'groups' => [
                ['type' => 'matching', 'data' => [
                    'variant' => 'general',
                    'instructions' => 'Who will do each part of the project? Choose the correct letter, A, B or C. You may use any letter more than once.',
                    'options_title' => 'People',
                    'allow_reuse' => true,
                    'options' => [['text' => 'Leo'], ['text' => 'Priya'], ['text' => 'Leo and Priya together']],
                    'questions' => [
                        ['prompt' => 'designing the questionnaire', 'answer' => 'C'],
                        ['prompt' => 'contacting the bus company', 'answer' => 'A'],
                        ['prompt' => 'analysing the data', 'answer' => 'B'],
                        ['prompt' => 'making the graphs', 'answer' => 'B'],
                        ['prompt' => 'writing the conclusion', 'answer' => 'C'],
                    ],
                ]],
                ['type' => 'table_completion', 'data' => [
                    'word_limit' => 'Write ONE WORD AND/OR A NUMBER for each answer.',
                    'title' => 'Commuting survey: project plan',
                    'headers' => 'Stage | Details | Deadline',
                    'rows' => [
                        ['cells' => 'Pilot | Check the [[clarity]] of the questions with ten people | Monday'],
                        ['cells' => 'Main survey | Collect at least [[150|one hundred and fifty]] responses from student commuters | End of March'],
                        ['cells' => 'Analysis | Use the [[statistics|statistical]] software from the library | Mid-April'],
                        ['cells' => 'Presentation | No more than [[12|twelve]] slides | [[Friday]], 14 May'],
                    ],
                ]],
            ],
        ],
        [
            'audio' => '/mock/audio/part-4.mp3',
            'audio_duration' => 45,
            'groups' => [
                ['type' => 'gap_fill', 'data' => [
                    'instructions' => 'Complete the notes below.',
                    'word_limit' => 'Write ONE WORD ONLY for each answer.',
                    'layout' => 'notes',
                    'title' => 'Urban beekeeping',
                    'text' => implode("\n", [
                        '# Growth',
                        '- Rooftop hives in large cities have increased sharply in the last [[decade]]',
                        '- City bees often make more honey because of the variety of [[plants|flowers]] in gardens and parks',
                        '- Cities are usually [[warmer]] than the countryside, so bees stay active for longer',
                        '# Problems',
                        '- Too many hives in one area can cause a shortage of [[food]]',
                        '- Bees can be harmed by air [[pollution]]',
                        '- Neighbours are often afraid of being [[stung]]',
                        '# Rules',
                        '- Beekeepers must register with the local [[council]]',
                        '- Hives must be at least two [[metres|meters|m]] from a public path',
                        '# Research',
                        '- Scientists place small [[sensors]] inside hives to check their health',
                        '- Bees seem to prefer flowers that are [[blue]] or purple',
                    ]),
                ]],
            ],
        ],
    ],
];
