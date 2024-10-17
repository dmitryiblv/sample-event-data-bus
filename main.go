package main

import (
    "fmt"
    "log"
    "time"
    "strconv"
    "math/rand"
    "sync"
    "sync/atomic"
)

const (
    EventBusSize            = 256           // Event data bus size
                                            // Bigger bus size - more concurrent operations can be done

    MessageSizeMax          = 16            // Event message max length, in bytes
    PublishQueueLen         = 4<<10         // Max messages in a publish queue per Producer
    EventBusWriteDelayUs    = 1             // Emulate storage write acknowledged delay, in microsec

    // Exchange in parallel
    Producers               = 10            // Producers countevent_data_bus
                                            // More Producers and Consumers - more exchanges can be done
    ProducerMessages        = 1000000       // Amount of messages per Producer
    Consumers               = 10            // Consumers count
    Verbose                 = false
    
    // Exchange ~sequentially
    //Producers               = 1
    //ProducerMessages        = 10000000
    //Consumers               = 1
    //Verbose                 = false
    
    // Exchange with verbose
    //Producers               = 2
    //ProducerMessages        = 2
    //Consumers               = 2
    //Verbose                 = true
)

type EventBus struct {
    Data EventBusData
    publishQueues EventBusPublishQueues // Published messages ready to be read
}

// Map stores pointers to the data value structs (bus cells). So we can access map values
// on read/write from many threads without need to lock map itself.
type EventBusData map[EventBusDataKey]*EventBusDataValue

type EventBusDataKey uint16

type EventBusDataValue struct {
    Messages []EventBusDataValueMessage // Producer pushes messages to this list
    offsetRead int64 // Consumer reads messages from this offset
    mu sync.RWMutex // Producers can publish messages with equal bus keys at the same time
}

// Used fixed-size bytes array to guarantee, that value will not be escaped to the heap
type EventBusDataValueMessage [MessageSizeMax]byte

// For simplicity, we use notification channels per pair Producer-Consumer. Each pair has
// it's own notification channel.
type EventBusPublishQueues map[int]*chan EventBusPublishQueuesChanValue

type EventBusPublishQueuesChanValue struct {
    Key EventBusDataKey
    MsgNum int // Message num at the Producer
}

// Simple func to calculate bus key (~hash) for the message. Equal messages must have equal keys.
func (b *EventBus) Key(msg string) EventBusDataKey {
    s := 0
    for i := 0; i < len(msg); i++ {
        s += int(msg[i])
    }
    return EventBusDataKey(s % EventBusSize)
}

func main() {

    if Producers != Consumers { // For simplicity
        log.Fatalln("Producers must be equal to Consumers")
    }

    // Init event data bus

    fmt.Printf("Initializing event data bus: size: %v\n", EventBusSize)
    bus := &EventBus{Data: make(EventBusData, EventBusSize)}
    for bk := EventBusDataKey(0); bk < EventBusDataKey(EventBusSize); bk++ {
        bus.Data[bk] = &EventBusDataValue{
            Messages: make([]EventBusDataValueMessage, 0, ProducerMessages),
        }
    }

    // Initializing notification channels per pair Producer-Consumer
    bus.publishQueues = make(EventBusPublishQueues, Producers)
    for p := 0; p < Producers; p++ {
        ch := make(chan EventBusPublishQueuesChanValue, PublishQueueLen)
        bus.publishQueues[p] = &ch
    }

    // Generate messages
    // Prepare messages for write-read to not waste time on this during exchange process

    messagesTotal := Producers * ProducerMessages
    fmt.Printf("Generating messages: total: %v\n", messagesTotal)
    rand.Seed(time.Now().UTC().UnixNano())
    messages := make([][]string, Producers)
    for p := 0; p < Producers; p++ {
        messages[p] = make([]string, ProducerMessages)
        for m := 0; m < ProducerMessages; m++ {
            // Message to publish
            messages[p][m] = "m_" +
                strconv.Itoa(rand.Intn(1000)) + "_" +
                strconv.Itoa(rand.Intn(1000)) + "_" +
                strconv.Itoa(rand.Intn(1000))
            if l := len(messages[p][m]); l > MessageSizeMax {
                log.Fatalf("Bad message size: %v", l)
            }
        }
    }

    // Calculate messages bus keys (~ message hash)
    // Prepare keys to not waste time on this during exchange process

    fmt.Printf("Calculating messages bus keys\n")
    messagesKeys := make([][]EventBusDataKey, Producers)
    for p := 0; p < Producers; p++ {
        messagesKeys[p] = make([]EventBusDataKey, ProducerMessages)
        for m := 0; m < ProducerMessages; m++ {
            messagesKeys[p][m] = bus.Key(messages[p][m])
        }
    }

    // Run

    fmt.Printf("Producers: %v\n", Producers)
    fmt.Printf("Consumers: %v\n", Consumers)

    fmt.Printf("Sending messages ...\n")

    timeStart := time.Now()

    // Producers

    for p := 0; p < Producers; p++ {
        p := p // for compatibility with older versions of golang

        go func() { // Run Producer
            for m := 0; m < ProducerMessages; m++ { // Publish messages (write to the Bus)

                msg := messages[p][m] // Message to publish (write)
                bk := messagesKeys[p][m] // Bus key
                bv := bus.Data[bk] // Pointer to bus value (cell). We access map only on read.

                var mbyt EventBusDataValueMessage
                copy(mbyt[:], msg) // Convert string to array of bytes

                func() { // Use local func and defer unlock to guarantee that unlock will be done
                    bv.mu.Lock()
                    defer bv.mu.Unlock()

                    bv.Messages = append(bv.Messages, mbyt) // Write message to the bus

                    if EventBusWriteDelayUs > 0 { // Emulate write delay
                        time.Sleep(time.Microsecond * EventBusWriteDelayUs)
                    }
                    if Verbose {
                        fmt.Printf("producer[%v]: msgNum: %v, busKey: %v, msg: %v\n", p, m, bk, msg)
                    }
                }()

                // Notify Consumer that bus was written
                *bus.publishQueues[p] <- EventBusPublishQueuesChanValue{Key: bk, MsgNum: m}
            }
            close(*bus.publishQueues[p])
        }()
    }

    // Consumers

    var wgConsumers sync.WaitGroup
    wgConsumers.Add(Consumers)

    for c := 0; c < Consumers; c++ {
        c := c // for compatibility with older versions of golang

        go func() { // Run consumer
            defer wgConsumers.Done()

            for pub := range *bus.publishQueues[c] { // Read as soon as bus write event occurred

                bk := pub.Key // Bus key
                bv := bus.Data[bk] // Pointer to bus value (cellevent_data_bus). We access map only on read.

                func() { // Use local func and defer unlock to guarantee that unlock will be done
                    bv.mu.RLock()
                    defer bv.mu.RUnlock()

                    // Use atomic, as we have only read-lock here
                    offset := atomic.LoadInt64(&bv.offsetRead)
                    atomic.AddInt64(&bv.offsetRead, 1) // offsetRead++
                    //runtime.Gosched() // Not need

                    msg := string(bv.Messages[offset][:]) // Read message from the bus

                    if Verbose {
                        fmt.Printf("consumer[%v]: msgNum: %v, busKey: %v, msg: %v\n", c, pub.MsgNum, bk, msg)
                    }
                }()
            }
        }()
    }
    wgConsumers.Wait() // Wait till Consumers have read all messages

    // Result
    timeTaken := time.Since(timeStart).Milliseconds()
    rps := 0
    if timeTaken > 0 {
        rps = int(float64(messagesTotal) / (float64(int(timeTaken)) / 1000))
    }
    fmt.Printf("RPS: %v\n", rps)
    fmt.Printf("Time taken: %v ms\n", timeTaken)
}
