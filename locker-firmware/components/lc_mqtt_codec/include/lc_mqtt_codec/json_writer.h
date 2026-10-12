#pragma once

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>
#include <stdio.h>
#include <string.h>

/*
 * A minimal JSON writer into a caller buffer. Once a value does not fit, every
 * further call is ignored and `ok` stays false, so the caller checks it once
 * at the end. Not part of the codec's interface.
 */

typedef struct {
    char *out;
    size_t capacity;
    size_t length;
    bool ok;
} lc_json_writer_t;

static inline void lc_json_begin(lc_json_writer_t *writer, char *out, size_t capacity)
{
    writer->out = out;
    writer->capacity = capacity;
    writer->length = 0;
    writer->ok = capacity > 0;
    if (capacity > 0) {
        out[0] = '\0';
    }
}

static inline void lc_json_raw(lc_json_writer_t *writer, const char *text)
{
    size_t length = strlen(text);
    if (!writer->ok || length >= writer->capacity - writer->length) {
        writer->ok = false;
        return;
    }
    memcpy(writer->out + writer->length, text, length + 1);
    writer->length += length;
}

/* A quoted string. Control characters and quotes are escaped. */
static inline void lc_json_string(lc_json_writer_t *writer, const char *text)
{
    lc_json_raw(writer, "\"");
    for (const char *c = text; *c != '\0' && writer->ok; ++c) {
        char piece[8];
        unsigned char byte = (unsigned char)*c;
        if (byte == '"' || byte == '\\') {
            piece[0] = '\\';
            piece[1] = (char)byte;
            piece[2] = '\0';
        } else if (byte < 0x20) {
            snprintf(piece, sizeof(piece), "\\u%04x", (unsigned)byte);
        } else {
            piece[0] = (char)byte;
            piece[1] = '\0';
        }
        lc_json_raw(writer, piece);
    }
    lc_json_raw(writer, "\"");
}

/* `,"name":` or `"name":` at the start of an object. */
static inline void lc_json_key(lc_json_writer_t *writer, const char *name, bool first)
{
    if (!first) {
        lc_json_raw(writer, ",");
    }
    lc_json_string(writer, name);
    lc_json_raw(writer, ":");
}

static inline void lc_json_unsigned(lc_json_writer_t *writer, uint64_t value)
{
    char text[24];
    snprintf(text, sizeof(text), "%llu", (unsigned long long)value);
    lc_json_raw(writer, text);
}

static inline void lc_json_bool(lc_json_writer_t *writer, bool value)
{
    lc_json_raw(writer, value ? "true" : "false");
}

/* The length of the document, or 0 when something did not fit. */
static inline size_t lc_json_finish(const lc_json_writer_t *writer)
{
    return writer->ok ? writer->length : 0;
}
