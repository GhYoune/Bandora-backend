<?php

/* Sample Writing and Speaking tests in the teacher's authoring format. Original content. */

return [
    'writing-1' => ['tasks' => [
        [
            'number' => 1,
            'kind' => 'letter',
            'prompt' => "You recently stayed at a hotel and left a personal item in your room.\n\nWrite a letter to the hotel manager. In your letter",
            'bullets' => "describe the item you left behind\nexplain where in the room you think you left it\nsay how you would like the item to be returned to you",
            'closing' => 'Write at least 150 words. You do NOT need to write any addresses. Begin your letter as follows: Dear Sir or Madam,',
        ],
        [
            'number' => 2,
            'kind' => 'essay',
            'prompt' => "Some people think that working from home is better for employees, while others believe that working in an office is more productive.\n\nDiscuss both these views and give your own opinion.\n\nGive reasons for your answer and include any relevant examples from your own knowledge or experience.",
            'closing' => 'Write at least 250 words.',
        ],
    ]],
    'writing-2' => ['tasks' => [
        [
            'number' => 2,
            'kind' => 'essay',
            'prompt' => "In many countries, young people are leaving rural areas to live in cities.\n\nWhat are the causes of this trend? What can be done to encourage young people to stay in the countryside?\n\nGive reasons for your answer and include any relevant examples from your own knowledge or experience.",
            'closing' => 'Write at least 250 words.',
        ],
    ]],
    'writing-3' => ['tasks' => [
        [
            'number' => 1,
            'kind' => 'letter',
            'prompt' => "A friend is planning to move to the town or city where you live and has asked for your advice.\n\nWrite a letter to your friend. In your letter",
            'bullets' => "suggest a good area to live in\nexplain how to find a job there\noffer to help in some way when they arrive",
            'closing' => 'Write at least 150 words. You do NOT need to write any addresses. Begin your letter as follows: Dear ……………,',
        ],
    ]],
    'speaking-1' => [
        'part1' => [
            'topic' => 'Your home town',
            'answer_seconds' => 45,
            'questions' => [
                ['text' => 'Where is your home town, and what is it like?'],
                ['text' => 'What do you like most about living there?'],
                ['text' => 'Has your home town changed much since you were a child?'],
                ['text' => 'Would you like to live there in the future? Why or why not?'],
            ],
        ],
        'part2' => [
            'topic' => 'Describe a useful skill you have learned.',
            'bullets' => "what the skill is\nwhen and how you learned it\nhow often you use it",
            'closing' => 'and explain why this skill has been useful to you.',
            'prep_seconds' => 60,
            'speak_seconds' => 120,
            'follow_ups' => [['text' => 'Do you think you will teach this skill to someone else one day?']],
        ],
        'part3' => [
            'topic' => 'Learning new skills',
            'answer_seconds' => 75,
            'questions' => [
                ['text' => 'What skills do young people most need to succeed at work today?'],
                ['text' => 'Is it better to learn a new skill from a teacher or from online videos?'],
                ['text' => 'Should employers pay for their staff to learn new skills?'],
                ['text' => 'How might the skills people need change in the next twenty years?'],
            ],
        ],
    ],
];
