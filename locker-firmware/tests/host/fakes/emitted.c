#include "emitted.h"

#include <stdio.h>
#include <stdlib.h>

#include "unity.h"

cJSON *emit_message(const char *schema, const char *json)
{
    cJSON *message = cJSON_Parse(json);
    TEST_ASSERT_NOT_NULL_MESSAGE(message, "the encoder did not produce JSON");
    TEST_ASSERT_TRUE_MESSAGE(cJSON_IsObject(message), "the message is not a JSON object");

    const char *path = getenv("LC_EMITTED_MESSAGES");
    if (path != NULL && path[0] != '\0') {
        FILE *file = fopen(path, "a");
        TEST_ASSERT_NOT_NULL_MESSAGE(file, "cannot open the LC_EMITTED_MESSAGES file");
        fprintf(file, "{\"schema\":\"%s\",\"message\":%s}\n", schema, json);
        fclose(file);
    }
    return message;
}

const char *emitted_string(const cJSON *object, const char *key)
{
    const cJSON *item = cJSON_GetObjectItemCaseSensitive(object, key);
    TEST_ASSERT_TRUE_MESSAGE(cJSON_IsString(item), key);
    return item->valuestring;
}

long emitted_integer(const cJSON *object, const char *key)
{
    const cJSON *item = cJSON_GetObjectItemCaseSensitive(object, key);
    TEST_ASSERT_TRUE_MESSAGE(cJSON_IsNumber(item), key);
    long value = (long)item->valuedouble;
    TEST_ASSERT_TRUE_MESSAGE((double)value == item->valuedouble, key);
    return value;
}
